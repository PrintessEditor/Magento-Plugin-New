<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Catalog\Model\Product;
use Psr\Log\LoggerInterface;

class SaveTokenToCart implements ObserverInterface
{
    private RequestInterface $request;
    private SerializerInterface $serializer;
    private LoggerInterface $logger;

    public function __construct(
        RequestInterface $request,
        SerializerInterface $serializer,
        LoggerInterface $logger
    )
    {
        $this->request    = $request;
        $this->serializer = $serializer;
        $this->logger     = $logger;
    }

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

        if (!empty($params['thumbnailUrl'])) {
            $additionalOptions['printess_thumbnail_url'] = [
                'label' => 'Printess Thumbnail',
                'value' => $params['thumbnailUrl'],
            ];
        }

        $item->addOption([
            'product_id' => $item->getProductId(),
            'code'       => 'additional_options',
            'value'      => $this->serializer->serialize($additionalOptions),
        ]);

        $pageCount = max(0, (int)($params['printessPageCount'] ?? 0));
        $includedPages = max(0, (int)($params['printessIncludedPages'] ?? 0));

        $formFields = json_decode((string)($params['printessFormFields'] ?? '{}'), true) ?: [];
        if (!is_array($formFields)) {
            $formFields = [];
        }
        $formFields = $this->mergeTrustedFormFields($item, $formFields);

        // Strip PAGE_COUNT from the quote item so it never appears in cart or order display.
        // We've already captured the value above via info_buyRequest; removing it here is safe.
        $this->removeInternalOptionsFromDisplay($item);

        // PAGE_COUNT custom option is the authoritative page count when available,
        // since it comes from the trusted buy request rather than a plain hidden field.
        if (isset($formFields['PAGE_COUNT']) && ($trustedCount = max(0, (int)$formFields['PAGE_COUNT'])) > 0) {
            $pageCount = $trustedCount;
        }

        $includedPages = min($includedPages, $pageCount);
        $billablePages = max(0, $pageCount - $includedPages);

        if ($billablePages > 0) {
            $pagePricing = $item->getProduct()->getData('printess_page_pricing');
            if (is_string($pagePricing)) {
                $pagePricing = json_decode($pagePricing, true) ?: [];
            }
            $pagePricing = is_array($pagePricing) ? $pagePricing : [];

            $pricePerPage = $this->resolvePricePerPage($pagePricing, $formFields);

            if ($pricePerPage > 0) {
                $customPrice = (float)$item->getProduct()->getFinalPrice() + $billablePages * $pricePerPage;
                $item->setCustomPrice($customPrice);
                $item->setOriginalCustomPrice($customPrice);
                $item->getProduct()->setIsSuperMode(true);
            }
        }
    }

    private function removeInternalOptionsFromDisplay($item): void
    {
        try {
            foreach (($item->getProduct()->getOptions() ?: []) as $option) {
                if ($option->getType() !== 'field' || strtoupper((string)$option->getTitle()) !== 'PAGE_COUNT') {
                    continue;
                }
                $optionId = (string)$option->getOptionId();
                $item->removeOption('option_' . $optionId);

                $optionIdsOpt = $item->getOptionByCode('option_ids');
                if ($optionIdsOpt) {
                    $ids = array_filter(
                        explode(',', (string)$optionIdsOpt->getValue()),
                        static fn(string $id) => trim($id) !== $optionId
                    );
                    $optionIdsOpt->setValue(implode(',', $ids));
                }
                break;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Printess: failed to remove internal option from display', [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function resolvePricePerPage(array $rules, array $formFields): float
    {
        $bestPrice = 0.0;
        $bestScore = -1;

        foreach ($rules as $rule) {
            $conditions = trim((string)($rule['conditions'] ?? ''));
            $price      = (float)($rule['pricePerPage'] ?? 0);

            if ($conditions === '') {
                if ($bestScore < 0) { $bestScore = 0; $bestPrice = $price; }
                continue;
            }

            $parts    = explode(',', $conditions);
            $score    = 0;
            $allMatch = true;

            foreach ($parts as $part) {
                $kv = explode('=', $part, 2);
                if (count($kv) !== 2) { continue; }
                $key     = trim($kv[0]);
                $val     = strtolower(trim($kv[1]));
                $current = strtolower((string)($formFields[$key] ?? ''));
                if ($current !== $val) { $allMatch = false; break; }
                $score++;
            }

            if ($allMatch && $score > $bestScore) { $bestScore = $score; $bestPrice = $price; }
        }

        return $bestScore >= 0 ? $bestPrice : 0.0;
    }

    private function mergeTrustedFormFields($item, array $formFields): array
    {
        $trusted = $this->extractTrustedFormFieldsFromBuyRequest($item);
        foreach ($trusted as $key => $value) {
            $formFields[$key] = $value;
        }
        return $formFields;
    }

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

    private function extractTrustedCustomOptionFields(Product $product, array $selectedOptions): array
    {
        $trusted = [];

        try {
            foreach (($product->getOptions() ?: []) as $option) {
                $optionType = $option->getType();
                $optionId   = (string)$option->getOptionId();

                if (!isset($selectedOptions[$optionId])) {
                    continue;
                }

                if (in_array($optionType, ['drop_down', 'radio'], true)) {
                    $selectedValueId = (string)$selectedOptions[$optionId];
                    foreach ((array)$option->getValues() as $value) {
                        if ((string)$value->getOptionTypeId() !== $selectedValueId) {
                            continue;
                        }
                        $trusted[(string)$option->getTitle()] = (string)$value->getTitle();
                        break;
                    }
                } elseif ($optionType === 'field') {
                    // Text field options carry their raw submitted value directly.
                    $trusted[(string)$option->getTitle()] = (string)$selectedOptions[$optionId];
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Printess: custom option pricing field extraction failed', [
                'error' => $e->getMessage()
            ]);
        }

        return $trusted;
    }

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
