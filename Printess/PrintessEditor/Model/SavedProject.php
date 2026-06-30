<?php
namespace Printess\PrintessEditor\Model;

use Magento\Framework\Model\AbstractModel;

class SavedProject extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(ResourceModel\SavedProject::class);
    }
}
