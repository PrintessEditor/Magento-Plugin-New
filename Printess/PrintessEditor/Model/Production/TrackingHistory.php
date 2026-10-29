<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Production;

/**
 * PURE. Turns a job's stored tracking list (`printess_pdf_jobs.tracking_json`, entries
 * `{trackingId, trackingUrl, shippedAt, send}`, see DropshipCallbackParser) into display rows for the admin
 * "Printess Production" tab (PMD-25), each with the dropship order number of the send it belongs to.
 *
 * Order number of send n: the job's current `dropship_shop_order_id` for the current send; for an earlier send the
 * `shop_order_id` recorded in `history_json` by the submitter (entries `{send, shop_order_id, ...}`); otherwise it is
 * computed like the submitter does (DropshipShopOrderId::build). Entries without a send (0, legacy) get no number.
 */
final class TrackingHistory
{
    /**
     * @param array $trackingList decoded tracking_json (non-array entries are ignored)
     * @param array $history      decoded history_json
     * @return list<array{send: int, shop_order_id: string, tracking_id: string, tracking_url: string, shipped_at: string}>
     *     newest first; tracking_url only when it is an http(s) URL
     */
    public static function rows(
        array $trackingList,
        array $history,
        string $incrementId,
        int $currentSend,
        string $currentShopOrderId
    ): array {
        $recorded = self::recordedShopOrderIds($history);
        $rows = [];
        foreach ($trackingList as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $send = is_numeric($entry['send'] ?? null) ? max(0, (int) $entry['send']) : 0;
            $rows[] = [
                'send'          => $send,
                'shop_order_id' => self::shopOrderIdForSend($send, $incrementId, $currentSend, $currentShopOrderId, $recorded),
                'tracking_id'   => self::str($entry['trackingId'] ?? ($entry['tracking_id'] ?? null)),
                'tracking_url'  => self::safeUrl(self::str($entry['trackingUrl'] ?? ($entry['tracking_url'] ?? null))),
                'shipped_at'    => self::str($entry['shippedAt'] ?? ($entry['shipped_at'] ?? null)),
            ];
        }
        // Stored in arrival order, so the newest is last.
        return array_reverse($rows);
    }

    /**
     * The order number the dropshipper received for one send.
     *
     * @param array<int, string> $recorded send => shop_order_id from recordedShopOrderIds()
     */
    public static function shopOrderIdForSend(
        int $send,
        string $incrementId,
        int $currentSend,
        string $currentShopOrderId,
        array $recorded = []
    ): string {
        if ($send <= 0) {
            return '';
        }
        $currentShopOrderId = trim($currentShopOrderId);
        if ($send === $currentSend && $currentShopOrderId !== '') {
            return $currentShopOrderId;
        }
        if (isset($recorded[$send]) && $recorded[$send] !== '') {
            return $recorded[$send];
        }
        return trim($incrementId) !== '' ? DropshipShopOrderId::build($incrementId, $send) : '';
    }

    /**
     * send => shop_order_id from history entries that recorded both (the last one of a send wins).
     *
     * @return array<int, string>
     */
    public static function recordedShopOrderIds(array $history): array
    {
        $map = [];
        foreach ($history as $entry) {
            if (!is_array($entry) || !is_numeric($entry['send'] ?? null)) {
                continue;
            }
            $send = (int) $entry['send'];
            $id = self::str($entry['shop_order_id'] ?? null);
            if ($send > 0 && $id !== '') {
                $map[$send] = $id;
            }
        }
        return $map;
    }

    /** Only http(s) URLs may become links. */
    public static function safeUrl(string $url): string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) ? $url : '';
    }

    /** Decodes a JSON list column; anything else is an empty list. */
    public static function decodeList(mixed $json): array
    {
        if (!is_string($json) || trim($json) === '') {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) && array_is_list($data) ? $data : [];
    }

    private static function str(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        return is_int($value) || is_float($value) ? (string) $value : '';
    }
}
