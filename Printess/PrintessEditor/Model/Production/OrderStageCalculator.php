<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Production;

/**
 * Derives the single order-level Printess status from the order's jobs.
 *
 * PURE: plain arrays in, a status code (or null) out. Job status strings are duplicated from Model\PdfJob on
 * purpose so this class has no Magento dependency.
 *
 * Only dropship jobs (production_type 'dropship') count; print jobs never influence the order status.
 *
 * | Condition (evaluated top to bottom)                     | Result            |
 * |---------------------------------------------------------|-------------------|
 * | any dropship job `failed`                               | ERROR             |
 * | ≥1 dropship job `shipped` and ≥1 not shipped            | PARTIALLY_SHIPPED |
 * | no dropship jobs, or all of them `shipped`              | null              |
 * | least advanced unshipped job decides:                   |                   |
 * |   `awaiting_*` / `pending` / unknown status             | null              |
 * |   `submitting` / `producing`                            | RENDERING         |
 * |   `rendered`, dropship_state not 1/10/11                | SUBMITTED         |
 * |   `rendered`, dropship_state 1, 10 or 11 (1 = combined  | IN_PRODUCTION     |
 * |   order only partly shipped, tracking "partial")        |                   |
 *
 * null means "no Printess stage": the updater then restores the state's default status.
 * Unknown statuses rank lowest (null) so an unexpected value never advertises progress that may not exist.
 */
class OrderStageCalculator
{
    private const TYPE_DROPSHIP = 'dropship';

    private const STATUS_FAILED     = 'failed';
    private const STATUS_SHIPPED    = 'shipped';
    private const STATUS_SUBMITTING = 'submitting';
    private const STATUS_PRODUCING  = 'producing';
    private const STATUS_RENDERED   = 'rendered';

    /** Partner (dropshipper) states that mean "in production". */
    private const IN_PRODUCTION_STATES = [1, 10, 11];

    /** Stage ranks, lowest = least advanced. Index 0 = no stage. */
    private const RANKED_STAGES = [
        null,
        OrderStage::RENDERING,
        OrderStage::SUBMITTED,
        OrderStage::IN_PRODUCTION,
    ];

    /**
     * @param array<int, array{production_type?: mixed, status?: mixed, dropship_state?: mixed}> $jobs
     */
    public function calculate(array $jobs): ?string
    {
        $shipped   = 0;
        $unshipped = [];

        foreach ($jobs as $job) {
            if (!is_array($job) || (string) ($job['production_type'] ?? '') !== self::TYPE_DROPSHIP) {
                continue;
            }
            $status = (string) ($job['status'] ?? '');
            if ($status === self::STATUS_FAILED) {
                // Error takes precedence over everything else (D9).
                return OrderStage::ERROR;
            }
            if ($status === self::STATUS_SHIPPED) {
                $shipped++;
            } else {
                $unshipped[] = $job;
            }
        }

        if ($shipped > 0 && $unshipped !== []) {
            return OrderStage::PARTIALLY_SHIPPED;
        }
        if ($unshipped === []) {
            return null;
        }

        $minRank = PHP_INT_MAX;
        foreach ($unshipped as $job) {
            $minRank = min($minRank, $this->rank($job));
            if ($minRank === 0) {
                break;
            }
        }

        return self::RANKED_STAGES[$minRank];
    }

    /**
     * Rank of a single unshipped dropship job (index into RANKED_STAGES).
     */
    private function rank(array $job): int
    {
        switch ((string) ($job['status'] ?? '')) {
            case self::STATUS_SUBMITTING:
            case self::STATUS_PRODUCING:
                return 1;
            case self::STATUS_RENDERED:
                $state = $job['dropship_state'] ?? null;
                if ($state !== null && is_numeric($state)
                    && in_array((int) $state, self::IN_PRODUCTION_STATES, true)
                ) {
                    return 3;
                }
                return 2;
            default:
                // awaiting_payment, awaiting_approval, pending and anything unknown.
                return 0;
        }
    }
}
