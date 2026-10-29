<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Production;

/**
 * PURE: turns one entry of POST /dropship/orders/list into what the poller should do with the matching job(s).
 *
 * Partner states: 0 submitted, 10 received by partner, 11 print-ready, 1 shipped, 98 cancelled, 99 error.
 * A combined (linked) order reports state 1 with trackingId "partial" while only some parcels left — that is
 * still progress, not a shipment, because there is no tracking number to put on a Magento shipment yet.
 * Unknown states are only recorded (action 'none'), so a new partner state never fails or ships an item.
 */
class DropshipOrderStateMapper
{
    public const ACTION_SHIPPED  = 'shipped';
    public const ACTION_PROGRESS = 'progress';
    public const ACTION_FAILED   = 'failed';
    public const ACTION_NONE     = 'none';

    public const STATE_SUBMITTED   = 0;
    public const STATE_SHIPPED     = 1;
    public const STATE_RECEIVED    = 10;
    public const STATE_PRINT_READY = 11;
    public const STATE_CANCELLED   = 98;
    public const STATE_ERROR       = 99;

    private const PARTIAL_TRACKING_ID = 'partial';

    /**
     * @return array{action: string, state: ?int, tracking_id: ?string, tracking_url: ?string, error: ?string}
     */
    public function map(array $dropshipOrder): array
    {
        $state       = $this->intOrNull($dropshipOrder['state'] ?? null);
        $trackingId  = $this->str($dropshipOrder['trackingId'] ?? null);
        $trackingUrl = $this->str($dropshipOrder['trackingPayload'] ?? null);

        $result = [
            'action'       => self::ACTION_NONE,
            'state'        => $state,
            'tracking_id'  => null,
            'tracking_url' => null,
            'error'        => null,
        ];

        if ($state === null) {
            return $result; // switch compares loosely: null would match case 0
        }

        switch ($state) {
            case self::STATE_SHIPPED:
                if ($trackingId === '' || strcasecmp($trackingId, self::PARTIAL_TRACKING_ID) === 0) {
                    $result['action'] = self::ACTION_PROGRESS;
                    break;
                }
                $result['action']       = self::ACTION_SHIPPED;
                $result['tracking_id']  = $trackingId;
                $result['tracking_url'] = $trackingUrl !== '' ? $trackingUrl : null;
                break;

            case self::STATE_SUBMITTED:
            case self::STATE_RECEIVED:
            case self::STATE_PRINT_READY:
                $result['action'] = self::ACTION_PROGRESS;
                break;

            case self::STATE_CANCELLED:
                $result['action'] = self::ACTION_FAILED;
                $result['error']  = 'Cancelled by the dropshipper';
                break;

            case self::STATE_ERROR:
                $message          = $this->str($dropshipOrder['errorMessage'] ?? null);
                $result['action'] = self::ACTION_FAILED;
                $result['error']  = $message !== '' ? $message : 'The dropshipper reported an error';
                break;
        }

        return $result;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
            return (int) trim($value);
        }
        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }
        return null;
    }

    private function str(mixed $value): string
    {
        return is_scalar($value) && !is_bool($value) ? trim((string) $value) : '';
    }
}
