<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Production;

/**
 * PURE. Reads the Printess dropship tracking callback (dropship.callbackType 3) and maintains a job's tracking list.
 *
 * Body: `{orderId, orderStatus: "shipped", lineItems: [{lineItemId, nonce, trackingId, trackingUrl}]}`.
 * Only the nonce identifies an item (it is matched against the jobs of the order named in the callback URL);
 * Printess's own orderId/lineItemId and orderStatus are ignored. A callback lists only the items of that shipment.
 *
 * Tracking list entries (`printess_pdf_jobs.tracking_json`): `{trackingId, trackingUrl, shippedAt, send}`.
 */
class DropshipCallbackParser
{
    /**
     * @return list<array{nonce: string, tracking_id: string, tracking_url: string}>
     */
    public function parse(array $payload): array
    {
        $lineItems = $payload['lineItems'] ?? null;
        if (!is_array($lineItems)) {
            return [];
        }

        $entries = [];
        foreach ($lineItems as $lineItem) {
            if (!is_array($lineItem)) {
                continue;
            }
            $nonce = $this->str($lineItem['nonce'] ?? null);
            if ($nonce === '') {
                continue;
            }
            $entries[] = [
                'nonce'        => $nonce,
                'tracking_id'  => $this->str($lineItem['trackingId'] ?? null),
                'tracking_url' => $this->str($lineItem['trackingUrl'] ?? null),
            ];
        }

        return $entries;
    }

    /**
     * Appends one shipment to a tracking list unless it is a repeat: an entry with the same trackingId, trackingUrl
     * AND the same send already exists. Printess retries callbacks (and the poller may report what a callback already
     * did), so the same shipment of one send may arrive more than once. A re-sent item is a new shipment even when
     * the dropshipper reports the same (or an empty) tracking as in an earlier send.
     *
     * @param array $existing decoded tracking list (malformed entries are dropped; an entry without send counts as 0)
     * @param array $shipment {trackingId?, trackingUrl?, shippedAt?} (also accepts tracking_id / tracking_url)
     * @param int   $send     the job's current dropship send count the shipment belongs to
     * @return array{list: list<array>, added: bool}
     */
    public function mergeTracking(array $existing, array $shipment, int $send): array
    {
        $list = [];
        foreach ($existing as $entry) {
            if (is_array($entry)) {
                $list[] = $entry;
            }
        }

        $send        = max(0, $send);
        $trackingId  = $this->str($shipment['trackingId'] ?? ($shipment['tracking_id'] ?? null));
        $trackingUrl = $this->str($shipment['trackingUrl'] ?? ($shipment['tracking_url'] ?? null));

        foreach ($list as $entry) {
            if ($this->str($entry['trackingId'] ?? null) === $trackingId
                && $this->str($entry['trackingUrl'] ?? null) === $trackingUrl
                && $this->sendOf($entry) === $send
            ) {
                return ['list' => $list, 'added' => false];
            }
        }

        $shippedAt = $this->str($shipment['shippedAt'] ?? null);
        $list[] = [
            'trackingId'  => $trackingId,
            'trackingUrl' => $trackingUrl,
            'shippedAt'   => $shippedAt !== '' ? $shippedAt : gmdate(DATE_ATOM),
            'send'        => $send,
        ];

        return ['list' => $list, 'added' => true];
    }

    private function sendOf(array $entry): int
    {
        $send = $entry['send'] ?? null;
        return is_numeric($send) ? max(0, (int) $send) : 0;
    }

    /**
     * Decodes a stored tracking_json value; anything unreadable counts as an empty list.
     *
     * @return list<array>
     */
    public function decodeTracking(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_filter($decoded, 'is_array'));
    }

    private function str(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return '';
    }
}
