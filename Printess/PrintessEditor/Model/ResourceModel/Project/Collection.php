<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\ResourceModel\Project;

use Printess\PrintessEditor\Model\Project;
use Printess\PrintessEditor\Model\ResourceModel\Project as ProjectResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(Project::class, ProjectResource::class);
    }
}
