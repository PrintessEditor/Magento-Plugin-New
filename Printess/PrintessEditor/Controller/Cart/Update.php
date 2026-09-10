<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Cart;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Model\Product;
use Printess\PrintessEditor\Helper\Config as PrintessConfig;
use Printess\PrintessEditor\Model\PrintessApi;
use Printess\PrintessEditor\Model\ThumbnailUrlValidator;
use Psr\Log\LoggerInterface;

/**
 * Updates the Printess save token and re-prices an existing cart item.
 *
 * Repricing always runs from server-fetched /book/info data whenever the save token changes.
 * There is no fast path that skips it — a client-supplied flag to bypass repricing would let a
 * shopper swap in a more expensive design after the item was priced for a cheaper one.
 *
 * POST /printess/cart/update
 *   itemId       int     Quote item ID
 *   saveToken    string  New Printess save token
 *   thumbnailUrl string  New thumbnail URL (optional)
 *   form_key     string  Magento form key
 */
class Update implements HttpPostActionInterface
{
    public function __construct(
        private RequestInterface $request,
        private JsonFactory $jsonFactory,
        private Validator $formKeyValidator,
        private CheckoutSession $checkoutSession,
        private SerializerInterface $serializer,
        private CustomerSession $customerSession,
        private PrintessConfig $printessConfig,
        private ProductRepositoryInterface $productRepository,
        private CartRepositoryInterface $cartRepository,
        private StoreManagerInterface $storeManager,
        private LoggerInterface $logger,
        private ThumbnailUrlValidator $thumbnailUrlValidator
    ) {
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        if (!$this->formKeyValidator->validate($this->request)) {
            return $result->setData(['success' => false, 'message' => 'Invalid form key']);
        }

        if (!$this->customerSession->isLoggedIn()) {
            return $result->setHttpResponseCode(401)->setData(['success' => false, 'message' => 'Authentication required']);
        }

        $itemId       = (int)$this->request->getParam('itemId');
        $saveToken    = (string)$this->request->getParam('saveToken');
        $thumbnailUrl = $this->thumbnailUrlValidator->validate(
            (string)$this->request->getParam('thumbnailUrl', '')
        );

        if (!str_starts_with($saveToken, 'st:') || strlen($saveToken) > 512) {
            return $result->setData(['success' => false, 'message' => 'Invalid save token format']);
        }

        if (!$itemId || !$saveToken) {
            return $result->setData(['success' => false, 'message' => 'Missing required parameters']);
        }

        try {
            $quote = $this->checkoutSession->getQuote();
            $item  = $quote->getItemById($itemId);

            if (!$item) {
                return $result->setData(['success' => false, 'message' => 'Cart item not found']);
            }

            // Update save token and thumbnail
            $additionalOptions = [];
            if ($existing = $item->getOptionByCode('additional_options')) {
                $additionalOptions = $this->serializer->unserialize($existing->getValue());
            }

            $additionalOptions['printess_save_token'] = [
                'label' => 'Printess Save Token',
                'value' => $saveToken,
            ];

            if ($thumbnailUrl !== null) {
                $additionalOptions['printess_thumbnail_url'] = [
                    'label' => 'Printess Thumbnail',
                    'value' => $thumbnailUrl,
                ];
            }

            // Load product with store scope early — needed for custom option update, page
            // pricing, AND to determine whether this product relies on book/info below.
            try {
                $storeId = (int)$this->storeManager->getStore()->getId();
                $product = $this->productRepository->getById((int)$item->getProductId(), false, $storeId);
            } catch (\Throwable $e) {
                $product = $item->getProduct();
            }

            // Fetch authoritative page data from the Printess API — no client-supplied values trusted.
            // Returns null on API failure.
            $bookInfo = $this->fetchBookInfo($saveToken);
            if ($bookInfo === null) {
                // Calendars have no book pages/spreads structure, so /book/info always fails
                // for them. Only abort when page-based pricing is configured — otherwise fall
                // back to stored values and continue.
                $pagePricingRaw        = $product->getData('printess_page_pricing');
                $pagePricingConfigured = !empty(
                    is_array($pagePricingRaw) ? $pagePricingRaw : (json_decode((string)$pagePricingRaw, true) ?: [])
                );

                if ($pagePricingConfigured) {
                    return $result->setData([
                        'success' => false,
                        'message' => 'Could not retrieve book information. Please try again.',
                    ]);
                }

                // Live form field values from the editor, used as a fallback for non-book
                // documents since /book/info never succeeds for them.
                $submittedFormFields = [];
                $rawSubmittedFields  = (string)$this->request->getParam('formFields', '');
                if ($rawSubmittedFields !== '') {
                    $decodedSubmitted = json_decode($rawSubmittedFields, true);
                    if (is_array($decodedSubmitted)) {
                        foreach ($decodedSubmitted as $fieldName => $fieldValue) {
                            if (is_string($fieldName) && $fieldName !== '' && is_scalar($fieldValue)) {
                                $submittedFormFields[$fieldName] = (string)$fieldValue;
                            }
                        }
                    }
                }

                if (!empty($submittedFormFields)) {
                    $storedFormFields = $submittedFormFields;
                } else {
                    $storedFormFields = [];
                    if (isset($additionalOptions['printess_form_fields']['value'])) {
                        $decoded          = json_decode((string)$additionalOptions['printess_form_fields']['value'], true);
                        $storedFormFields = is_array($decoded) ? $decoded : [];
                    }
                }
                $storedMinPages = isset($additionalOptions['printess_included_pages']['value'])
                    ? (int)$additionalOptions['printess_included_pages']['value']
                    : 0;

                $bookInfo = [0, $storedMinPages, $storedFormFields];
            }
            [$pageCount, $minPages, $formFields] = $bookInfo;

            // Merge Magento option labels into form fields for condition matching.
            $mergedFormFields = $this->mergeTrustedFormFields($item, $product, $formFields);

            // Refresh the stored form fields so the next cart edit opens with the correct selections.
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

            // Update the Magento custom option selections on the item so the cart
            // display reflects changes made in the builder (e.g. DOCUMENT_SIZE, COVER_TYPE).
            $this->updateItemCustomOptions($item, $product, $mergedFormFields);

            // Re-apply page pricing using server-authoritative page data.
            $additionalOptions['printess_included_pages'] = [
                'label' => 'Printess Included Pages',
                'value' => (string)$minPages,
            ];

            $billablePages = max(0, $pageCount - $minPages);

            $newBasePrice = $this->computeOptionBasePrice($product, $formFields);

            if ($billablePages > 0) {
                $pagePricingRaw = $product->getData('printess_page_pricing');
                $pagePricing    = is_array($pagePricingRaw)
                    ? $pagePricingRaw
                    : (json_decode((string)$pagePricingRaw, true) ?: []);

                $pricePerPage = $this->resolvePricePerPage($pagePricing, $mergedFormFields);

                if ($pricePerPage > 0) {
                    // Compute the base price from the product's option prices for the
                    // NEW selection (formFields).  This is correct even when the user
                    // changes size/cover in the builder before clicking "Update".
                    $customPrice = $newBasePrice + $billablePages * $pricePerPage;
                    $item->setCustomPrice($customPrice);
                    $item->setOriginalCustomPrice($customPrice);
                    $product->setIsSuperMode(true);

                    // Keep printess_page_delta in sync so RestorePageDeltaAfterTierPrice
                    // can re-add the extra-pages cost after service-level re-pricing.
                    $delta = $billablePages * $pricePerPage;
                    $item->addOption([
                        'product_id' => $item->getProductId(),
                        'code'       => 'printess_page_delta',
                        'value'      => $this->serializer->serialize(['additional_cost' => $delta]),
                    ]);
                } else {
                    // pricePerPage resolved to 0 for this combination — treat extra pages as
                    // free; clear any stale custom price and page delta from a prior state.
                    $item->setCustomPrice(null);
                    $item->setOriginalCustomPrice(null);
                    $item->removeOption('printess_page_delta');
                }
            } else {
                // No extra pages — let Magento reprice from the updated info_buyRequest.
                $item->setCustomPrice(null);
                $item->setOriginalCustomPrice(null);
                // Clear the stored delta — no extra pages, nothing to restore.
                $item->removeOption('printess_page_delta');
            }

            // Always keep the stored base price in sync (covers both branches above).
            $additionalOptions['printess_base_price'] = [
                'label' => 'Printess Base Price',
                'value' => (string)$newBasePrice,
            ];
            $item->addOption([
                'product_id' => $item->getProductId(),
                'code'       => 'additional_options',
                'value'      => $this->serializer->serialize($additionalOptions),
            ]);

            $this->cartRepository->save($quote);

            return $result->setData(['success' => true]);
        } catch (\Exception $e) {
            $this->logger->error('Printess cart update failed: ' . $e->getMessage());
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Call the Printess book/info API and return [pageCount, minPages, formFields].
     * Returns null on any API failure so the caller can abort without touching existing pricing.
     *
     * @param  string $saveToken
     * @return array{0: int, 1: int, 2: array}|null
     */
    private function fetchBookInfo(string $saveToken): ?array
    {
        $serviceToken = $this->printessConfig->getServiceToken();
        if ($serviceToken === '' || $saveToken === '') {
            return null;
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
            $this->logger->warning('Printess: book/info call failed in Update', [
                'error'     => $e->getMessage(),
                'saveToken' => substr($saveToken, 0, 30) . '…',
            ]);
            return null;
        }
    }

    /**
     * Convert the formFieldData block from book/info into a flat key→value map.
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

        return array_map('strval', $fields);
    }

    /**
     * Enrich $formFields with the Magento option labels from the item's buy request
     * (e.g. "Book Size" => "Large Landscape").  Page pricing conditions are often written
     * using these human-readable labels rather than Printess internal codes.
     */
    private function mergeTrustedFormFields(
        \Magento\Quote\Model\Quote\Item $item,
        Product $product,
        array $formFields
    ): array {
        try {
            $buyRequestOption = $item->getOptionByCode('info_buyRequest');
            if (!$buyRequestOption) {
                return $formFields;
            }
            $buyRequest = $this->serializer->unserialize((string)$buyRequestOption->getValue());
            if (!is_array($buyRequest)) {
                return $formFields;
            }

            // Custom options: map optionId → selected label.
            // Only add keys not already present — the API-sourced $formFields contain
            // the user's current Printess field values; Magento option labels are additive.
            $selectedOptions = (array)($buyRequest['options'] ?? []);
            foreach (($product->getOptions() ?: []) as $option) {
                if (!in_array($option->getType(), ['drop_down', 'radio'], true)) {
                    continue;
                }
                $optionTitle = (string)$option->getTitle();
                // Skip if the pricing token already supplied this key.
                if (array_key_exists($optionTitle, $formFields)) {
                    continue;
                }
                $optionId = (string)$option->getOptionId();
                if (!isset($selectedOptions[$optionId])) {
                    continue;
                }
                $selectedValueId = (string)$selectedOptions[$optionId];
                foreach ((array)$option->getValues() as $value) {
                    if ((string)$value->getOptionTypeId() === $selectedValueId) {
                        $formFields[$optionTitle] = (string)$value->getTitle();
                        break;
                    }
                }
            }

            // Configurable super attributes: map attribute label → selected option label
            if ($product->getTypeId() === 'configurable') {
                $superAttributes = (array)($buyRequest['super_attribute'] ?? []);
                $typeInstance = $product->getTypeInstance();
                foreach ($typeInstance->getConfigurableAttributes($product) as $cfgAttr) {
                    $attributeId = (string)$cfgAttr->getAttributeId();
                    if (!isset($superAttributes[$attributeId])) {
                        continue;
                    }
                    $productAttr = $cfgAttr->getProductAttribute();
                    if (!$productAttr) {
                        continue;
                    }
                    $attrLabel = (string)$productAttr->getDefaultFrontendLabel();
                    // Don't overwrite keys already provided by the pricing token.
                    if (array_key_exists($attrLabel, $formFields)) {
                        continue;
                    }
                    $selectedValueIndex = (string)$superAttributes[$attributeId];
                    foreach ((array)$cfgAttr->getOptions() as $option) {
                        if ((string)($option['value_index'] ?? '') === $selectedValueIndex) {
                            $formFields[$attrLabel] = (string)($option['label'] ?? '');
                            break;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Printess update: form field merge failed', ['error' => $e->getMessage()]);
        }

        return $formFields;
    }

    /**
     * Update the Magento custom option selections on the quote item to match the
     * new Printess form field values.  This keeps the cart display (e.g. "DOCUMENT_SIZE:
     * 270x205") in sync after the user changes options in the builder.
     *
     * Matches by option title (which may be the Printess field name itself, e.g.
     * "DOCUMENT_SIZE") or by the human-readable label added via mergeTrustedFormFields
     * (e.g. "Book Size").  Both sets are present in $formFields after merging.
     */
    private function updateItemCustomOptions(
        \Magento\Quote\Model\Quote\Item $item,
        Product $product,
        array $formFields
    ): void {
        try {
            $buyRequestOption = $item->getOptionByCode('info_buyRequest');
            if (!$buyRequestOption) {
                return;
            }
            $buyRequest = $this->serializer->unserialize((string)$buyRequestOption->getValue());
            if (!is_array($buyRequest)) {
                return;
            }

            $buyRequestChanged = false;

            foreach (($product->getOptions() ?: []) as $option) {
                if (!in_array($option->getType(), ['drop_down', 'radio'], true)) {
                    continue;
                }

                $optionTitle = (string)$option->getTitle();
                if (!array_key_exists($optionTitle, $formFields)) {
                    continue;
                }

                $newValueLabel = (string)$formFields[$optionTitle];
                if ($newValueLabel === '') {
                    continue;
                }

                // Find the value whose title matches the new label.
                foreach ((array)$option->getValues() as $value) {
                    if ((string)$value->getTitle() !== $newValueLabel) {
                        continue;
                    }

                    $optionId = (string)$option->getOptionId();
                    $valueId  = (string)$value->getOptionTypeId();

                    // Only update if it actually changed.
                    $currentValueId = (string)($buyRequest['options'][$optionId] ?? '');
                    if ($currentValueId === $valueId) {
                        break;
                    }

                    // Update buy request so Magento's option renderer displays the right label.
                    if (!isset($buyRequest['options'])) {
                        $buyRequest['options'] = [];
                    }
                    $buyRequest['options'][$optionId] = $valueId;
                    $buyRequestChanged = true;

                    // Update the per-option item option record (stores the value ID for display).
                    $item->addOption([
                        'product_id' => $item->getProductId(),
                        'code'       => 'option_' . $optionId,
                        'value'      => $valueId,
                    ]);
                    break;
                }
            }

            if ($buyRequestChanged) {
                $item->addOption([
                    'product_id' => $item->getProductId(),
                    'code'       => 'info_buyRequest',
                    'value'      => $this->serializer->serialize($buyRequest),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Printess update: custom option update failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Compute the product base price for the given form field selections.
     * Sums product->getFinalPrice() + prices of matching custom option values.
     * This mirrors the JS "basePrice + computeOptionUpcharge()" pattern and gives
     * the correct base when the user changes size/cover in the builder.
     */
    private function computeOptionBasePrice(Product $product, array $formFields): float
    {
        $base = (float)$product->getFinalPrice();
        try {
            foreach (($product->getOptions() ?: []) as $option) {
                if (!in_array($option->getType(), ['drop_down', 'radio'], true)) {
                    continue;
                }
                $optionTitle = (string)$option->getTitle();
                if (!array_key_exists($optionTitle, $formFields)) {
                    continue;
                }
                $targetLabel = (string)$formFields[$optionTitle];
                if ($targetLabel === '') {
                    continue;
                }
                foreach ((array)$option->getValues() as $value) {
                    if ((string)$value->getTitle() === $targetLabel) {
                        $base += (float)$value->getPrice();
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Printess update: option base price computation failed', ['error' => $e->getMessage()]);
        }
        return $base;
    }

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
}
