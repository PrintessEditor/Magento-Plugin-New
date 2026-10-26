<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Production;

/**
 * The link between an order and its copies (PMD-21), stored as JSON in two `sales_order` columns:
 *
 * - on the COPY, `printess_reproduction_of`: {
 *       id, increment_id, customer_note (the original's note — the dropship dispatch notice),
 *       item_map {originalItemId: copyItemId}, discount_path 'collector'|'zero_price',
 *       shipping_method, shipping_description (the original's) }
 * - on the ORIGINAL, `printess_reproduced_as`: list of {id, increment_id, item_ids (original item ids), created_at}
 *
 * PURE: no Magento dependency, so the admin/customer views can parse the columns with the same rules.
 */
final class ReproductionLink
{
    public const DISCOUNT_PATH_COLLECTOR  = 'collector';
    public const DISCOUNT_PATH_ZERO_PRICE = 'zero_price';

    /** Amounts below this count as 0 (S1: grand total and tax of the copy must be exactly 0). */
    public const ZERO_EPSILON = 0.0001;

    /**
     * @param array<int|string, int|string> $itemMap original order item id => copy order item id
     */
    public static function buildReproductionOf(
        int $originalId,
        string $originalIncrementId,
        ?string $customerNote,
        array $itemMap,
        string $discountPath,
        string $shippingMethod = '',
        string $shippingDescription = '',
        string $message = ''
    ): string {
        if (!in_array($discountPath, [self::DISCOUNT_PATH_COLLECTOR, self::DISCOUNT_PATH_ZERO_PRICE], true)) {
            throw new \InvalidArgumentException(sprintf('unknown discount path "%s"', $discountPath));
        }
        $map = [];
        foreach ($itemMap as $from => $to) {
            $map[(string) (int) $from] = (int) $to;
        }
        return self::encode([
            'id'                   => $originalId,
            'increment_id'         => $originalIncrementId,
            'customer_note'        => $customerNote !== null ? $customerNote : '',
            // An object even when empty, so readers always get a map.
            'item_map'             => (object) $map,
            'discount_path'        => $discountPath,
            'shipping_method'      => $shippingMethod,
            'shipping_description' => $shippingDescription,
            // The merchant's full message to the customer (the discount label is cut at 255 characters).
            'message'              => $message,
        ]);
    }

    /**
     * @return array{id: int, increment_id: string, customer_note: string, item_map: array<int, int>,
     *     discount_path: string, shipping_method: string, shipping_description: string, message: string}|null
     */
    public static function parseReproductionOf(mixed $json): ?array
    {
        if (!is_string($json) || trim($json) === '') {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['id']) || (int) $data['id'] <= 0) {
            return null;
        }
        $map = [];
        foreach (is_array($data['item_map'] ?? null) ? $data['item_map'] : [] as $from => $to) {
            if ((int) $from > 0 && is_numeric($to)) {
                $map[(int) $from] = (int) $to;
            }
        }
        return [
            'id'                   => (int) $data['id'],
            'increment_id'         => self::scalar($data['increment_id'] ?? ''),
            'customer_note'        => self::scalar($data['customer_note'] ?? ''),
            'item_map'             => $map,
            'discount_path'        => self::scalar($data['discount_path'] ?? self::DISCOUNT_PATH_COLLECTOR),
            'shipping_method'      => self::scalar($data['shipping_method'] ?? ''),
            'shipping_description' => self::scalar($data['shipping_description'] ?? ''),
            'message'              => self::scalar($data['message'] ?? ''),
        ];
    }

    /**
     * @param list<int|string> $originalItemIds
     */
    public static function appendReproducedAs(
        mixed $existingJson,
        int $copyId,
        string $copyIncrementId,
        array $originalItemIds,
        string $createdAt
    ): string {
        $list = self::parseReproducedAs($existingJson);
        $list[] = [
            'id'           => $copyId,
            'increment_id' => $copyIncrementId,
            'item_ids'     => array_values(array_map('intval', $originalItemIds)),
            'created_at'   => $createdAt,
        ];
        return self::encode($list);
    }

    /**
     * Invalid entries and invalid JSON are dropped rather than failing the caller.
     *
     * @return list<array{id: int, increment_id: string, item_ids: list<int>, created_at: string}>
     */
    public static function parseReproducedAs(mixed $json): array
    {
        if (!is_string($json) || trim($json) === '') {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !array_is_list($data)) {
            return [];
        }
        $list = [];
        foreach ($data as $entry) {
            if (!is_array($entry) || !isset($entry['id']) || (int) $entry['id'] <= 0) {
                continue;
            }
            $ids = is_array($entry['item_ids'] ?? null) ? $entry['item_ids'] : [];
            $list[] = [
                'id'           => (int) $entry['id'],
                'increment_id' => self::scalar($entry['increment_id'] ?? ''),
                'item_ids'     => array_values(array_map('intval', array_filter($ids, 'is_numeric'))),
                'created_at'   => self::scalar($entry['created_at'] ?? ''),
            ];
        }
        return $list;
    }

    /**
     * Rate titles for the hidden carrier so Magento rebuilds the original description: the shipping total stores
     * `trim(carrier_title . ' - ' . method_title, ' -')`, so the description is split at its first " - ".
     *
     * @return array{carrier_title: string, method_title: string}
     */
    public static function splitShippingDescription(string $description, string $fallback): array
    {
        $description = trim($description);
        if ($description === '') {
            return ['carrier_title' => $fallback, 'method_title' => ''];
        }
        $pos = strpos($description, ' - ');
        if ($pos === false) {
            return ['carrier_title' => $description, 'method_title' => ''];
        }
        return [
            'carrier_title' => trim(substr($description, 0, $pos)),
            'method_title'  => trim(substr($description, $pos + 3)),
        ];
    }

    public static function isZero(float ...$amounts): bool
    {
        foreach ($amounts as $amount) {
            if (!(abs($amount) < self::ZERO_EPSILON)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Which top-level items to copy. `$candidates` describes every item of the original:
     * id => ['parent_id' => ?int, 'printess' => bool (carries a save token)]. A requested child item stands for its
     * parent. Unknown ids and items without a Printess design are ignored. Order follows `$candidates`.
     *
     * @param array<int|string, mixed> $requestedIds
     * @param array<int, array{parent_id: ?int, printess: bool}> $candidates
     * @return list<int>
     */
    public static function selectItemIds(array $requestedIds, array $candidates): array
    {
        $wanted = [];
        foreach ($requestedIds as $id) {
            if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                continue;
            }
            $id = (int) $id;
            if (!isset($candidates[$id])) {
                continue;
            }
            $parent = $candidates[$id]['parent_id'] ?? null;
            if ($parent !== null && $parent > 0 && isset($candidates[$parent])) {
                $id = $parent;
            }
            $wanted[$id] = true;
        }
        $selected = [];
        foreach ($candidates as $id => $candidate) {
            $parent = $candidate['parent_id'] ?? null;
            if (isset($wanted[$id]) && ($parent === null || $parent <= 0) && !empty($candidate['printess'])) {
                $selected[] = (int) $id;
            }
        }
        return $selected;
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function encode(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
