<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Production;

/**
 * Decides the status a freshly enqueued Printess job starts in.
 *
 * PURE: no Magento dependencies. Status strings match PdfJob::STATUS_* (duplicated here on purpose
 * so this class stays testable without loading the Magento model).
 */
final class InitialStatusPolicy
{
    public const STATUS_AWAITING_PAYMENT  = 'awaiting_payment';
    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';
    public const STATUS_PENDING           = 'pending';

    /**
     * Payment gate first (only when the merchant opted in), then the optional manual approval.
     * With both settings off, production starts immediately as before.
     *
     * A reproduction (admin copy of an order, plan §1.5) skips both gates: the merchant created it on purpose,
     * it is free and invoiced right after placement, so its items go to production straight away.
     */
    public function decide(bool $holdUntilPayment, bool $isPaid, bool $approve, bool $isReproduction = false): string
    {
        if ($isReproduction) {
            return self::STATUS_PENDING;
        }
        if ($holdUntilPayment && !$isPaid) {
            return self::STATUS_AWAITING_PAYMENT;
        }
        return $approve ? self::STATUS_AWAITING_APPROVAL : self::STATUS_PENDING;
    }
}
