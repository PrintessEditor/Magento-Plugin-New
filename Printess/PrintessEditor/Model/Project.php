<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model;

use Printess\PrintessEditor\Model\ResourceModel\Project as ProjectResource;
use Magento\Framework\Model\AbstractModel;

class Project extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(ProjectResource::class);
    }
}
