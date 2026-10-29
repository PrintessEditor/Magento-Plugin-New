<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Options;

/**
 * Removes Printess-internal entries (save token, thumbnail URL) from an item's option list before it is shown to
 * the customer (cart, storefront order pages, order emails). They stay in the order data and in the admin.
 *
 * Entries are matched by their key (`printess_*`, as stored in additional_options) and, should the keys have been
 * lost along the way, by their label. Pure: no Magento dependencies.
 */
final class InternalOptionFilter
{
    private const KEY_PREFIX = 'printess_';
    private const INTERNAL_LABELS = ['Printess Save Token', 'Printess Thumbnail'];

    /**
     * @param array<int|string, mixed> $options
     * @return array<int|string, mixed>
     */
    public static function strip(array $options): array
    {
        $kept = [];
        foreach ($options as $key => $option) {
            if (is_string($key) && str_starts_with($key, self::KEY_PREFIX)) {
                continue;
            }
            $label = is_array($option) && is_string($option['label'] ?? null) ? trim($option['label']) : null;
            if ($label !== null && in_array($label, self::INTERNAL_LABELS, true)) {
                continue;
            }
            $kept[$key] = $option;
        }
        // Keep list semantics for templates that expect 0..n keys.
        return array_is_list($options) ? array_values($kept) : $kept;
    }
}
