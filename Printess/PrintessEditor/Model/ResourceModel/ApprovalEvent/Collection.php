<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\ResourceModel\ApprovalEvent;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Printess\PrintessEditor\Model\Approval\Event;
use Printess\PrintessEditor\Model\ResourceModel\ApprovalEvent as EventResource;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'event_id';

    protected function _construct(): void
    {
        $this->_init(Event::class, EventResource::class);
    }
}
