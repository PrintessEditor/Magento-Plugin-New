<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Adminhtml\Api;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Raw;

class FormFields extends AbstractProxy implements HttpPostActionInterface
{
    public function execute(): Raw
    {
        $body = (string) $this->getRequest()->getContent();
        $data = json_decode($body, true) ?: [];

        return $this->proxy('/template/formFields/list', json_encode([
            'templateName' => (string) ($data['templateName'] ?? ''),
        ]));
    }
}
