<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Production;

/**
 * Order-level Printess production statuses (Magento order *statuses*, never states).
 *
 * PURE: no Magento dependencies, so the calculator, the data patch and tests share one source of truth.
 */
final class OrderStage
{
    /** At least one dropship job is being rendered by Printess. */
    public const RENDERING = 'printess_rendering';

    /** All unshipped dropship items are rendered and handed to the dropshipper. */
    public const SUBMITTED = 'printess_dropship_submitted';

    /** The dropshipper reports production (partner state 10/11) for every unshipped item. */
    public const IN_PRODUCTION = 'printess_dropship_in_production';

    /** Some, but not all, dropship items have shipped. */
    public const PARTIALLY_SHIPPED = 'printess_dropship_part_shipped'; // max. 32 chars (sales_order_status.status)

    /** Any dropship item failed. Shown only — the order is never held or canceled. */
    public const ERROR = 'printess_dropship_error';

    public const ALL = [
        self::RENDERING,
        self::SUBMITTED,
        self::IN_PRODUCTION,
        self::PARTIALLY_SHIPPED,
        self::ERROR,
    ];

    /** Default admin labels (renamable under Stores › Order Status). */
    public const LABELS = [
        self::RENDERING         => 'Printess: Rendering',
        self::SUBMITTED         => 'Printess: Sent to dropshipper',
        self::IN_PRODUCTION     => 'Printess: In production',
        self::PARTIALLY_SHIPPED => 'Printess: Partially shipped',
        self::ERROR             => 'Printess: Production error',
    ];

    /**
     * What customers see on the storefront (My Orders, order view): plain words instead of the admin names, and no
     * "error" (production problems are the merchant's to solve). Stored per store view in sales_order_status_label
     * by Setup\Patch\Data\AddStorefrontOrderStatusLabels and editable under Stores › Order Status.
     */
    public const STOREFRONT_LABELS = [
        self::RENDERING         => 'In production',
        self::SUBMITTED         => 'In production',
        self::IN_PRODUCTION     => 'In production',
        self::PARTIALLY_SHIPPED => 'Partially shipped',
        self::ERROR             => 'Processing',
    ];

    /**
     * Order states each status is assigned to. PARTIALLY_SHIPPED implies a shipment exists, which Magento
     * only allows once the order is `processing`.
     */
    public const STATE_ASSIGNMENTS = [
        self::RENDERING         => ['new', 'processing'],
        self::SUBMITTED         => ['new', 'processing'],
        self::IN_PRODUCTION     => ['new', 'processing'],
        self::PARTIALLY_SHIPPED => ['processing'],
        self::ERROR             => ['new', 'processing'],
    ];

    private function __construct()
    {
    }
}
