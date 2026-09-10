<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Adminhtml\Api;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Raw;

class Snippets extends AbstractProxy implements HttpPostActionInterface
{
    public function execute(): Raw
    {
        $body = (string) $this->getRequest()->getContent();
        $data = json_decode($body, true) ?: [];

        $payload = json_encode([
            'tags'          => array_values((array) ($data['tags'] ?? [])),
            'keywords'      => array_values((array) ($data['keywords'] ?? [])),
            'includeGlobal' => true,
            'productType'   => '*',
        ]);

        return $this->proxy('/layoutSnippets/load', $payload);
    }
}
