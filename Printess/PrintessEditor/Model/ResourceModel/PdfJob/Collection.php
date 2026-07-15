<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\ResourceModel\PdfJob;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Printess\PrintessEditor\Model\PdfJob;
use Printess\PrintessEditor\Model\ResourceModel\PdfJob as PdfJobResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(PdfJob::class, PdfJobResource::class);
    }
}
