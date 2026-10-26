<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Cart;

/**
 * Printess page pricing: the per-page surcharge a cart item gets on top of the product price when the design has
 * more pages than the template includes. Shared by the add-to-cart observer (params = the POSTed form) and the
 * reorder flows (params = the order item's info_buyRequest, which holds the same POSTed fields), so a reorder is
 * priced exactly like the original add-to-cart.
 *
 * Deliberately free of Magento imports so the pricing rules are unit-testable; apply() duck-types the quote item.
 */
class PagePricing
{
    /**
     * @param array<string, mixed> $params printessPageCount / printessIncludedPages / printessFormFields (JSON)
     * @return array{0: int, 1: int, 2: array<string, mixed>} [pageCount, includedPages, formFields]
     */
    public function decodePricingParams(array $params): array
    {
        $rawFields = $params['printessFormFields'] ?? '{}';
        $formFields = is_array($rawFields) ? $rawFields : (json_decode((string) $rawFields, true) ?: []);
        return [
            max(0, (int) ($params['printessPageCount'] ?? 0)),
            max(0, (int) ($params['printessIncludedPages'] ?? 0)),
            is_array($formFields) ? $formFields : [],
        ];
    }

    public function billablePages(int $pageCount, int $includedPages): int
    {
        return max(0, $pageCount - min($includedPages, $pageCount));
    }

    /**
     * Picks the most specific matching rule (most matching "key=value" conditions); a rule without conditions is the
     * fallback. Returns 0.0 when nothing matches.
     *
     * @param array<int, array<string, mixed>> $rules
     * @param array<string, mixed> $formFields
     */
    public function resolvePricePerPage(array $rules, array $formFields): float
    {
        $bestPrice = 0.0;
        $bestScore = -1;

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $conditions = trim((string) ($rule['conditions'] ?? ''));
            $price      = (float) ($rule['pricePerPage'] ?? 0);

            if ($conditions === '') {
                if ($bestScore < 0) {
                    $bestScore = 0;
                    $bestPrice = $price;
                }
                continue;
            }

            $score    = 0;
            $allMatch = true;

            foreach (explode(',', $conditions) as $part) {
                $kv = explode('=', $part, 2);
                if (count($kv) !== 2) {
                    continue;
                }
                $key     = trim($kv[0]);
                $val     = strtolower(trim($kv[1]));
                $current = strtolower((string) ($formFields[$key] ?? ''));
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
     * @param mixed $pagePricing the product's printess_page_pricing attribute (JSON string or array)
     * @return array<int, array<string, mixed>>
     */
    public function normalizeRules(mixed $pagePricing): array
    {
        if (is_string($pagePricing)) {
            $pagePricing = json_decode($pagePricing, true) ?: [];
        }
        return is_array($pagePricing) ? $pagePricing : [];
    }

    /**
     * Sets the custom price of a (parent) quote item when the design has billable extra pages. No-op otherwise.
     *
     * @param \Magento\Quote\Model\Quote\Item $item
     * @param array<string, mixed> $params
     * @return bool whether a custom price was set
     */
    public function apply($item, array $params): bool
    {
        [$pageCount, $includedPages, $formFields] = $this->decodePricingParams($params);
        $billablePages = $this->billablePages($pageCount, $includedPages);
        if ($billablePages <= 0) {
            return false;
        }

        $product = $item->getProduct();
        $pricePerPage = $this->resolvePricePerPage(
            $this->normalizeRules($product->getData('printess_page_pricing')),
            $formFields
        );
        if ($pricePerPage <= 0) {
            return false;
        }

        $customPrice = (float) $product->getFinalPrice() + $billablePages * $pricePerPage;
        $item->setCustomPrice($customPrice);
        $item->setOriginalCustomPrice($customPrice);
        $product->setIsSuperMode(true);

        return true;
    }
}
