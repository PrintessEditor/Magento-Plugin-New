<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model;

use Magento\Framework\Model\AbstractModel;
use Printess\PrintessEditor\Model\ResourceModel\PdfJob as PdfJobResource;

/**
 * Tracks async Printess production (print PDF or dropship) for a single order item.
 *
 * Status flow:
 *   awaiting_payment / awaiting_approval → released            → pending
 *   Print jobs:
 *     pending   → produce() called         → producing
 *     producing → job finished             → complete
 *     producing → job error/timeout        → fail_count++; if >= MAX_FAIL_COUNT → failed
 *   Dropship jobs:
 *     pending    → saved before HTTP call  → submitting (idempotency marker)
 *     submitting → /dropship/produce ok    → producing (rendering)
 *     producing  → rendered, handed over   → rendered
 *     rendered   → dropshipper shipped     → shipped
 *     any        → permanent error         → failed
 */
class PdfJob extends AbstractModel
{
    public const STATUS_AWAITING_PAYMENT  = 'awaiting_payment';
    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';
    public const STATUS_PENDING           = 'pending';
    public const STATUS_SUBMITTING        = 'submitting';
    public const STATUS_PRODUCING         = 'producing';
    public const STATUS_RENDERED          = 'rendered';
    public const STATUS_SHIPPED           = 'shipped';
    public const STATUS_COMPLETE          = 'complete';
    public const STATUS_FAILED            = 'failed';

    public const TYPE_PRINT    = 'print';
    public const TYPE_DROPSHIP = 'dropship';

    public const CALLBACK_MODE_CALLBACK = 'callback';
    public const CALLBACK_MODE_POLL     = 'poll';

    /** Printess dropship callbackType: 0 = none (poll), 1 = legacy per-order URL, 3 = URL with one nonce per item. */
    public const DROPSHIP_CALLBACK_TYPE_NONE   = 0;
    public const DROPSHIP_CALLBACK_TYPE_LEGACY = 1;
    public const DROPSHIP_CALLBACK_TYPE_NONCES = 3;

    public const MAX_FAIL_COUNT = 3;

    protected function _construct(): void
    {
        $this->_init(PdfJobResource::class);
    }

    public function isDropship(): bool
    {
        return (string) $this->getData('production_type') === self::TYPE_DROPSHIP;
    }

    /**
     * Appends a timestamped entry to history_json so the admin can see how a job moved
     * through its states. A corrupt history is reset rather than blocking the status change.
     */
    public function appendHistory(array $entry): void
    {
        $history = [];
        $raw = $this->getData('history_json');
        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded) && array_is_list($decoded)) {
                    $history = $decoded;
                }
            } catch (\JsonException) {
                $history = [];
            }
        }

        $history[] = ['at' => gmdate('c')] + $entry;

        $this->setData(
            'history_json',
            json_encode($history, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
        );
    }
}
