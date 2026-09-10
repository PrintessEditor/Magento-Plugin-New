<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Catalog\Model\Product;
use Printess\PrintessEditor\Helper\Config as PrintessConfig;
use Printess\PrintessEditor\Model\PrintessApi;
use Printess\PrintessEditor\Model\ThumbnailUrlValidator;
use Psr\Log\LoggerInterface;

class SaveTokenToCart implements ObserverInterface
{
    /**
     * @var RequestInterface
     */
    private RequestInterface $request;

    /**
     * @var PrintessConfig
     */
    private PrintessConfig $printessConfig;

    /**
     * @var SerializerInterface
     */
    private SerializerInterface $serializer;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var ThumbnailUrlValidator
     */
    private ThumbnailUrlValidator $thumbnailUrlValidator;

    /**
     * Constructor.
     *
     * @param RequestInterface      $request
     * @param PrintessConfig        $printessConfig
     * @param SerializerInterface   $serializer
     * @param LoggerInterface       $logger
     * @param ThumbnailUrlValidator $thumbnailUrlValidator
     */
    public function __construct(
        RequestInterface $request,
        PrintessConfig $printessConfig,
        SerializerInterface $serializer,
        LoggerInterface $logger,
        ThumbnailUrlValidator $thumbnailUrlValidator
    ) {
        $this->request               = $request;
        $this->printessConfig        = $printessConfig;
        $this->serializer            = $serializer;
        $this->logger                = $logger;
        $this->thumbnailUrlValidator = $thumbnailUrlValidator;
    }

    /**
     * Store Printess token and pricing data on the cart item.
     *
     * @param Observer $observer
     *
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $params = $this->getParams();

        if (empty($params['saveToken'])) {
            return;
        }

        $item = $observer->getQuoteItem();
        $item = $item->getParentItem() ?: $item;

        $additionalOptions = [];
        if ($existing = $item->getOptionByCode('additional_options')) {
            $additionalOptions = $this->serializer->unserialize($existing->getValue());
        }

        $additionalOptions['printess_save_token'] = [
            'label' => 'Printess Save Token',
            'value' => $params['saveToken'],
        ];

        if (!empty($params['printessProjectId'])) {
            $additionalOptions['printess_project_id'] = [
                'label' => 'Printess Project ID',
                'value' => (string)(int)$params['printessProjectId'],
            ];
        }

        $validatedThumbnailUrl = $this->thumbnailUrlValidator->validate($params['thumbnailUrl'] ?? null);
        if ($validatedThumbnailUrl !== null) {
            $additionalOptions['printess_thumbnail_url'] = [
                'label' => 'Printess Thumbnail',
                'value' => $validatedThumbnailUrl,
            ];
        }

        // Capture the product base price NOW, before setCustomPrice() is called.
        // After $quote->save() Magento's totals collector overwrites $item->getPrice()
        // with getCalculationPrice() (= custom_price), so we must snapshot it here.
        $additionalOptions['printess_base_price'] = [
            'label' => 'Printess Base Price',
            'value' => (string)(float)$item->getPrice(),
        ];

        [$pageCount, $includedPages, $formFields] = $this->fetchBookInfo($params['saveToken']);

        $includedPages = min($includedPages, $pageCount);

        // Store includedPages so the cart edit flow can initialise _minPages correctly.
        if ($pageCount > 0) {
            $additionalOptions['printess_included_pages'] = [
                'label' => 'Printess Included Pages',
                'value' => (string)$includedPages,
            ];
        }

        // Store the price-relevant form fields (DOCUMENT_SIZE, COVER_TYPE, etc.) so
        // the cart edit flow can reseed them into the Printess builder on re-open.
        if (!empty($formFields) && is_array($formFields)) {
            $additionalOptions['printess_form_fields'] = [
                'label' => 'Printess Form Fields',
                'value' => json_encode($formFields),
            ];
        }

        $item->addOption([
            'product_id' => $item->getProductId(),
            'code'       => 'additional_options',
            'value'      => $this->serializer->serialize($additionalOptions),
        ]);

        $billablePages = max(0, $pageCount - $includedPages);

        if ($billablePages > 0) {
            $formFields = $this->mergeTrustedFormFields($item, $formFields);

            $pagePricing = $item->getProduct()->getData('printess_page_pricing');
            if (is_string($pagePricing)) {
                $pagePricing = json_decode($pagePricing, true) ?: [];
            }
            $pagePricing = is_array($pagePricing) ? $pagePricing : [];

            $pricePerPage = $this->resolvePricePerPage($pagePricing, $formFields);

            if ($pricePerPage > 0) {
                // Use the snapshotted item price (service-level adjusted, set before
                // any custom_price override) so the base reflects the actual Magento
                // price for this specific option combination.
                $basePrice   = (float)$item->getPrice();
                $customPrice = $basePrice + $billablePages * $pricePerPage;
                $item->setCustomPrice($customPrice);
                $item->setOriginalCustomPrice($customPrice);
                $item->getProduct()->setIsSuperMode(true);

                // Store the extra-pages delta so RestorePageDeltaAfterTierPrice can
                // re-add it when QuoteTierPriceHandler resets the price (e.g. on cart
                // qty update or quote merge on login).
                $delta = $billablePages * $pricePerPage;
                $item->addOption([
                    'product_id' => $item->getProductId(),
                    'code'       => 'printess_page_delta',
                    'value'      => $this->serializer->serialize(['additional_cost' => $delta]),
                ]);
                return;
            }
        }

        // book/info succeeded but no extra pages are billable. Write a zero-delta sentinel
        // so ApplyAdditionalPagePrice skips its redundant book/info call for this item.
        if ($pageCount > 0) {
            $item->addOption([
                'product_id' => $item->getProductId(),
                'code'       => 'printess_page_delta',
                'value'      => $this->serializer->serialize(['additional_cost' => 0]),
            ]);
        }
    }

    /**
     * Call the Printess book/info API to obtain verified pricing data.
     *
     * Returns [pageCount, minPages, formFields].
     * minPages = minimumSpreadCount * 2 - 2  (matches the JS formula).
     * formFields is a flat key→value map for resolvePricePerPage() conditions.
     * On any failure returns [0, 0, []] so pricing is simply not applied.
     *
     * @param  string $saveToken
     * @return array{int, int, array}
     */
    private function fetchBookInfo(string $saveToken): array
    {
        $serviceToken = $this->printessConfig->getServiceToken();
        if ($serviceToken === '' || $saveToken === '') {
            return [0, 0, []];
        }

        try {
            $api    = new PrintessApi($serviceToken);
            $result = $api->bookInfo($saveToken);

            $pageCount  = (int)($result['inside']['pageCount'] ?? 0);
            $minSpreads = (int)($result['inside']['minimumSpreadCount'] ?? 0);
            $minPages   = max(0, $minSpreads * 2 - 2);
            $formFields = $this->parseFormFields($result['formFieldData'] ?? []);

            return [$pageCount, $minPages, $formFields];
        } catch (\Throwable $e) {
            $this->logger->warning('Printess: book/info call failed in SaveTokenToCart', [
                'error'     => $e->getMessage(),
                'saveToken' => substr($saveToken, 0, 30) . '…',
            ]);
            return [0, 0, []];
        }
    }

    /**
     * Convert the formFieldData block from book/info into a flat key→value map.
     *
     * The API may return formFields as:
     *   - an array of {name, value} objects
     *   - an already-flat associative array
     *
     * @param  array $formFieldData  The formFieldData node from the API response
     * @return array
     */
    private function parseFormFields(array $formFieldData): array
    {
        $fields = $formFieldData['formFields'] ?? [];
        if (!is_array($fields) || empty($fields)) {
            return [];
        }

        // Sequential list of objects: [{name: 'FIELD', value: 'VAL'}, ...]
        if (isset($fields[0])) {
            $map = [];
            foreach ($fields as $field) {
                $name = (string)($field['name'] ?? $field['fieldName'] ?? '');
                if ($name !== '') {
                    $map[$name] = (string)($field['value'] ?? $field['fieldValue'] ?? '');
                }
            }
            return $map;
        }

        // Already a key→value map
        return array_map('strval', $fields);
    }

    /**
     * Return the best-matching per-page price for the given form fields.
     *
     * @param array $rules
     * @param array $formFields
     *
     * @return float
     */
    private function resolvePricePerPage(array $rules, array $formFields): float
    {
        $bestPrice = 0.0;
        $bestScore = -1;

        foreach ($rules as $rule) {
            $conditions = trim((string)($rule['conditions'] ?? ''));
            $price      = (float)($rule['pricePerPage'] ?? 0);

            if ($conditions === '') {
                if ($bestScore < 0) {
                    $bestScore = 0;
                    $bestPrice = $price;
                }
                continue;
            }

            $parts    = explode(',', $conditions);
            $score    = 0;
            $allMatch = true;

            foreach ($parts as $part) {
                $kv = explode('=', $part, 2);
                if (count($kv) !== 2) {
                    continue;
                }
                $key     = trim($kv[0]);
                $val     = strtolower(trim($kv[1]));
                $current = strtolower((string)($formFields[$key] ?? ''));
                if ($current !== $val) {
                    $allMatch = false;
                    break;
                }
                $score++;
            }

            if ($allMatch && $score > $bestScore) {
                $bestScore = $score;
                $bestPrice = $price;
            }
        }

        return $bestScore >= 0 ? $bestPrice : 0.0;
    }

    /**
     * Merge trusted server-resolved form fields over submitted ones.
     *
     * @param mixed $item
     * @param array $formFields
     *
     * @return array
     */
    private function mergeTrustedFormFields($item, array $formFields): array
    {
        $trusted = $this->extractTrustedFormFieldsFromBuyRequest($item);
        foreach ($trusted as $key => $value) {
            $formFields[$key] = $value;
        }
        return $formFields;
    }

    /**
     * Extract trusted form field values from the item's buy request.
     *
     * @param mixed $item
     *
     * @return array
     */
    private function extractTrustedFormFieldsFromBuyRequest($item): array
    {
        $trusted = [];
        $buyRequestOption = $item->getOptionByCode('info_buyRequest');
        if (!$buyRequestOption) {
            return $trusted;
        }

        try {
            $buyRequest = $this->serializer->unserialize((string)$buyRequestOption->getValue());
        } catch (\Throwable $e) {
            $this->logger->warning('Printess: unable to parse info_buyRequest for pricing checks', [
                'error' => $e->getMessage()
            ]);
            return $trusted;
        }

        if (!is_array($buyRequest)) {
            return $trusted;
        }

        $product = $item->getProduct();
        $trusted = array_replace(
            $trusted,
            $this->extractTrustedCustomOptionFields($product, (array)($buyRequest['options'] ?? [])),
            $this->extractTrustedVariantFields($product, (array)($buyRequest['super_attribute'] ?? []))
        );

        return $trusted;
    }

    /**
     * Extract trusted form fields from product custom options.
     *
     * @param Product $product
     * @param array   $selectedOptions
     *
     * @return array
     */
    private function extractTrustedCustomOptionFields(Product $product, array $selectedOptions): array
    {
        $trusted = [];

        try {
            foreach (($product->getOptions() ?: []) as $option) {
                if (!in_array($option->getType(), ['drop_down', 'radio'], true)) {
                    continue;
                }

                $optionId = (string)$option->getOptionId();
                if (!isset($selectedOptions[$optionId])) {
                    continue;
                }

                $selectedValueId = (string)$selectedOptions[$optionId];
                foreach ((array)$option->getValues() as $value) {
                    if ((string)$value->getOptionTypeId() !== $selectedValueId) {
                        continue;
                    }
                    $trusted[(string)$option->getTitle()] = (string)$value->getTitle();
                    break;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Printess: custom option pricing field extraction failed', [
                'error' => $e->getMessage()
            ]);
        }

        return $trusted;
    }

    /**
     * Extract trusted form fields from configurable product variant attributes.
     *
     * @param Product $product
     * @param array   $selectedSuperAttributes
     *
     * @return array
     */
    private function extractTrustedVariantFields(Product $product, array $selectedSuperAttributes): array
    {
        $trusted = [];
        if ($product->getTypeId() !== 'configurable') {
            return $trusted;
        }

        try {
            $typeInstance = $product->getTypeInstance();
            foreach ($typeInstance->getConfigurableAttributes($product) as $cfgAttr) {
                $attributeId = (string)$cfgAttr->getAttributeId();
                if (!isset($selectedSuperAttributes[$attributeId])) {
                    continue;
                }
                $selectedValueIndex = (string)$selectedSuperAttributes[$attributeId];
                $productAttr = $cfgAttr->getProductAttribute();
                if (!$productAttr) {
                    continue;
                }

                foreach ((array)$cfgAttr->getOptions() as $option) {
                    if ((string)($option['value_index'] ?? '') !== $selectedValueIndex) {
                        continue;
                    }
                    $trusted[(string)$productAttr->getFrontendLabel()] = (string)($option['label'] ?? '');
                    break;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Printess: variant pricing field extraction failed', [
                'error' => $e->getMessage()
            ]);
        }

        return $trusted;
    }

    /**
     * Return decoded request parameters (JSON body or POST params).
     *
     * @return array
     */
    private function getParams(): array
    {
        $content = $this->request->getContent();
        if (!empty($content)) {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $this->request->getPostValue() ?: [];
    }
}
