<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Adminhtml\Api;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Raw;

class Directories extends AbstractProxy implements HttpPostActionInterface
{
    public function execute(): Raw
    {
        return $this->proxy('/directories/load', json_encode(['userId' => '']));
    }
}
