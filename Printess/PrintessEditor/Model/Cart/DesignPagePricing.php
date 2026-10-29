<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Cart;

use Magento\Catalog\Model\Product;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Printess\PrintessEditor\Model\PrintessApiProvider;
use Psr\Log\LoggerInterface;

/**
 * Server-side page pricing for a quote item (PR #8), shared by add-to-cart (SaveTokenToCart), the storefront reorder
 * (PrintessOptionsCopier) and the admin reorder (ApplyPagePricingOnAdminReorder), so a reorder is priced exactly
 * like the original add-to-cart and nothing price-relevant is taken from the browser.
 *
 * - fetch(): page count, included pages and form fields from Printess `/book/info` for the save token. Only for book
 *   products (page-pricing rules configured, f24); other templates are not asked, Printess would answer with an error.
 * - applyToItem(): custom-option and variant values from the item's buy request override the design's form fields
 *   (they are what the customer chose and paid for), the most specific page-pricing rule is picked, and the item gets
 *   custom price = base price + billable pages × price per page. The surcharge is also stored as the
 *   `printess_page_delta` item option (RestorePageDeltaAfterTierPrice / Cart\Update re-apply it); a design without
 *   billable pages stores a zero delta so later steps skip their own /book/info call.
 */
class DesignPagePricing
{
    public const DELTA_OPTION = 'printess_page_delta';

    public function __construct(
        private readonly PrintessApiProvider $apiProvider,
        private readonly PagePricing $pagePricing,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param Product|null $product when given and it isn't a book product (no page-pricing rules), Printess is not asked
     * @return array{0: int, 1: int, 2: array<string, string>} [pageCount, includedPages, formFields]; [0, 0, []] when
     *         not a book product, or Printess can't be asked (no service token, network error, unknown design), so
     *         pricing is not applied
     */
    public function fetch(string $saveToken, ?int $storeId, ?Product $product = null): array
    {
        if (trim($saveToken) === '' || ($product !== null && !$this->isBookProduct($product))) {
            return [0, 0, []];
        }
        try {
            return $this->pagePricing->parseBookInfo($this->apiProvider->forStore($storeId)->bookInfo($saveToken));
        } catch (\Throwable $e) {
            $this->logger->warning('Printess: book/info call for page pricing failed', [
                'error'     => $e->getMessage(),
                'saveToken' => substr($saveToken, 0, 30) . '…',
            ]);
            return [0, 0, []];
        }
    }

    /**
     * fetch() + applyToItem() for the item's own design.
     *
     * @param array<string, mixed>|null $buyRequest null: read the item's info_buyRequest option
     * @return bool whether a surcharge was applied
     */
    public function applyFromDesign(QuoteItem $item, string $saveToken, ?int $storeId, ?array $buyRequest = null): bool
    {
        $product = $item->getProduct();
        if (!$product instanceof Product || !$this->isBookProduct($product)) {
            return false;
        }
        [$pageCount, $includedPages, $formFields] = $this->fetch($saveToken, $storeId);
        return $this->applyToItem($item, $pageCount, $includedPages, $formFields, $buyRequest);
    }

    /** f24: only products with page-pricing rules are books for pricing; only those are sent to /book/info. */
    public function isBookProduct(Product $product): bool
    {
        return $this->pagePricing->hasRules($product->getData('printess_page_pricing'));
    }

    /**
     * @param array<string, string> $formFields the design's form fields (from /book/info)
     * @param array<string, mixed>|null $buyRequest null: read the item's info_buyRequest option
     * @return bool whether a surcharge was applied
     */
    public function applyToItem(
        QuoteItem $item,
        int $pageCount,
        int $includedPages,
        array $formFields,
        ?array $buyRequest = null
    ): bool {
        $billablePages = $this->pagePricing->billablePages($pageCount, $includedPages);
        $product = $item->getProduct();

        if ($billablePages > 0 && $product instanceof Product) {
            $formFields = array_replace(
                $formFields,
                $this->trustedFields($product, $buyRequest ?? $this->buyRequestOf($item))
            );
            $pricePerPage = $this->pagePricing->resolvePricePerPage(
                $this->pagePricing->normalizeRules($product->getData('printess_page_pricing')),
                $formFields
            );

            if ($pricePerPage > 0) {
                // The snapshotted item price (option-adjusted, set before any custom price) when Magento has set it;
                // a reorder item may not have one yet, then the product's final price.
                $basePrice   = (float) $item->getPrice() > 0 ? (float) $item->getPrice() : (float) $product->getFinalPrice();
                $delta       = $billablePages * $pricePerPage;
                $customPrice = $basePrice + $delta;
                $item->setCustomPrice($customPrice);
                $item->setOriginalCustomPrice($customPrice);
                $product->setIsSuperMode(true);
                $this->setDelta($item, $delta);
                return true;
            }
        }

        // /book/info answered but nothing is billable: a zero delta lets later steps skip their own /book/info call.
        if ($pageCount > 0) {
            $this->setDelta($item, 0.0);
        }
        return false;
    }

    private function setDelta(QuoteItem $item, float $delta): void
    {
        $item->addOption([
            'product_id' => $item->getProductId(),
            'code'       => self::DELTA_OPTION,
            'value'      => $this->serializer->serialize(['additional_cost' => $delta]),
        ]);
    }

    /** @return array<string, mixed> */
    private function buyRequestOf(QuoteItem $item): array
    {
        $option = $item->getOptionByCode('info_buyRequest');
        if (!$option) {
            return [];
        }
        try {
            $value = $this->serializer->unserialize((string) $option->getValue());
        } catch (\Throwable $e) {
            $this->logger->warning('Printess: unable to parse info_buyRequest for pricing checks', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
        return is_array($value) ? $value : [];
    }

    /**
     * Custom-option (drop-down / radio) and configurable-variant values the buy request selected, by option title /
     * attribute label, validated against the product's current options.
     *
     * @param array<string, mixed> $buyRequest
     * @return array<string, string>
     */
    private function trustedFields(Product $product, array $buyRequest): array
    {
        $trusted  = [];
        $selected = is_array($buyRequest['options'] ?? null) ? $buyRequest['options'] : [];
        try {
            foreach (($product->getOptions() ?: []) as $option) {
                if (!in_array($option->getType(), ['drop_down', 'radio'], true)) {
                    continue;
                }
                $chosen = $selected[(string) $option->getOptionId()] ?? null;
                if (!is_scalar($chosen)) {
                    continue;
                }
                foreach ((array) $option->getValues() as $value) {
                    if ((string) $value->getOptionTypeId() === (string) $chosen) {
                        $trusted[(string) $option->getTitle()] = (string) $value->getTitle();
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Printess: custom option pricing field extraction failed', ['error' => $e->getMessage()]);
        }

        $super = is_array($buyRequest['super_attribute'] ?? null) ? $buyRequest['super_attribute'] : [];
        if ($product->getTypeId() === 'configurable' && $super !== []) {
            try {
                foreach ($product->getTypeInstance()->getConfigurableAttributes($product) as $cfgAttr) {
                    $chosen = $super[(string) $cfgAttr->getAttributeId()] ?? null;
                    $productAttr = $cfgAttr->getProductAttribute();
                    if (!is_scalar($chosen) || !$productAttr) {
                        continue;
                    }
                    foreach ((array) $cfgAttr->getOptions() as $opt) {
                        if ((string) ($opt['value_index'] ?? '') === (string) $chosen) {
                            $trusted[(string) $productAttr->getFrontendLabel()] = (string) ($opt['label'] ?? '');
                            break;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Printess: variant pricing field extraction failed', ['error' => $e->getMessage()]);
            }
        }

        return $trusted;
    }
}
