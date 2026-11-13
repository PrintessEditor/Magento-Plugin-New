<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Approval;

use Magento\Framework\Model\AbstractModel;
use Printess\PrintessEditor\Model\ResourceModel\ApprovalEvent as EventResource;

/**
 * One history entry or comment of a submission (printess_approval_event).
 */
class Event extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(EventResource::class);
    }
}
