<?php
namespace Printess\PrintessEditor\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class SavedProject extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('printess_saved_projects', 'id');
    }
}
