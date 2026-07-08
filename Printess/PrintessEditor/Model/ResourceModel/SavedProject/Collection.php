<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\ResourceModel\SavedProject;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Printess\PrintessEditor\Model\SavedProject;
use Printess\PrintessEditor\Model\ResourceModel\SavedProject as SavedProjectResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(SavedProject::class, SavedProjectResource::class);
    }
}
