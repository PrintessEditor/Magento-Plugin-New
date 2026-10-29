<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Cart;

use Printess\PrintessEditor\Model\Product\FormFieldValues;

/**
 * Printess page pricing: the per-page surcharge a cart item gets on top of the product price when the design has
 * more pages than the template includes.
 *
 * Since PR #8 the inputs are never taken from the browser: page count, included pages and form fields come from
 * Printess `/book/info` for the item's save token (parseBookInfo()), and custom-option / variant values from the
 * item's own buy request override the design's form fields. DesignPagePricing applies this to quote items for
 * add-to-cart and both reorder flows. This class holds the pure rules, free of Magento imports for unit tests.
 */
class PagePricing
{
    /**
     * Reads Printess `/book/info` (PR #8): page count, included pages (minimumSpreadCount * 2 - 2, the editor's
     * formula, capped at the page count) and the saved form fields as a flat name => value map. The form fields may
     * come as a list of {name, value} (or {fieldName, fieldValue}) objects or as a map.
     *
     * @param array<string, mixed> $bookInfo
     * @return array{0: int, 1: int, 2: array<string, string>} [pageCount, includedPages, formFields]
     */
    public function parseBookInfo(array $bookInfo): array
    {
        $inside     = is_array($bookInfo['inside'] ?? null) ? $bookInfo['inside'] : [];
        $pageCount  = max(0, (int) ($inside['pageCount'] ?? 0));
        $minPages   = max(0, (int) ($inside['minimumSpreadCount'] ?? 0) * 2 - 2);
        $data       = is_array($bookInfo['formFieldData'] ?? null) ? $bookInfo['formFieldData'] : [];
        $fields     = is_array($data['formFields'] ?? null) ? $data['formFields'] : [];

        $map = [];
        if (array_is_list($fields)) {
            foreach ($fields as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $name = (string) ($field['name'] ?? $field['fieldName'] ?? '');
                if ($name !== '') {
                    $value = $field['value'] ?? $field['fieldValue'] ?? '';
                    $map[$name] = is_scalar($value) ? (string) $value : '';
                }
            }
        } else {
            foreach ($fields as $name => $value) {
                if (is_scalar($value)) {
                    $map[(string) $name] = (string) $value;
                }
            }
        }

        return [$pageCount, min($minPages, $pageCount), $map];
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
                // Same field-name matching as cart edit (Controller\Cart\Update): "document size" = DOCUMENT_SIZE.
                $current = strtolower(FormFieldValues::get($formFields, $key) ?? '');
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
     * Whether a product counts as a book for pricing (f24): it has at least one page-pricing rule. Only then is
     * Printess `/book/info` asked; for other templates (cards, labels, calendars, ...) Printess answers it with an
     * error, and there is no page surcharge to compute anyway. Same rule as cart edit (Controller\Cart\Update).
     *
     * @param mixed $pagePricing the product's printess_page_pricing attribute (JSON string or array)
     */
    public function hasRules(mixed $pagePricing): bool
    {
        return $this->normalizeRules($pagePricing) !== [];
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
}
