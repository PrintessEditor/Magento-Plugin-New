<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Adminhtml\Api;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Raw;

class Keywords extends AbstractProxy implements HttpPostActionInterface
{
    public function execute(): Raw
    {
        return $this->proxy('/keywords/layout/load', json_encode(['includeGlobal' => true]));
    }
}
