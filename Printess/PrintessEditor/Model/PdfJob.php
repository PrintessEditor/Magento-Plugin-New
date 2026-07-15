<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model;

use Magento\Framework\Model\AbstractModel;
use Printess\PrintessEditor\Model\ResourceModel\PdfJob as PdfJobResource;

/**
 * Tracks async Printess PDF production for a single order item.
 *
 * Status flow:
 *   pending  → produce() called   → producing
 *   producing → job finished      → complete
 *   producing → job error/timeout → fail_count++; if >= MAX_FAIL_COUNT → failed
 */
class PdfJob extends AbstractModel
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_PRODUCING = 'producing';
    public const STATUS_COMPLETE  = 'complete';
    public const STATUS_FAILED    = 'failed';

    public const MAX_FAIL_COUNT = 3;

    protected function _construct(): void
    {
        $this->_init(PdfJobResource::class);
    }
}
