<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Adminhtml\Api;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Raw;

class Templates extends AbstractProxy implements HttpPostActionInterface
{
    public function execute(): Raw
    {
        $body = (string) $this->getRequest()->getContent();
        $data = json_decode($body, true) ?: [];

        $payload = json_encode([
            'templateName' => $data['templateName'] ?? null,
            'directoryId'  => isset($data['directoryId']) ? (int) $data['directoryId'] : null,
        ]);

        return $this->proxy('/templates/user/load', $payload);
    }
}
