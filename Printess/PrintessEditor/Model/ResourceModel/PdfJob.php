<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class PdfJob extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('printess_pdf_jobs', 'job_id');
    }
}
