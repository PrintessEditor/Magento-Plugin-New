<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Adminhtml\Api;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Catalog\Api\Data\ProductCustomOptionInterfaceFactory;
use Magento\Catalog\Api\Data\ProductCustomOptionValuesInterfaceFactory;
use Magento\Catalog\Api\ProductCustomOptionRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;

class GenerateCustomOptions extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Printess_PrintessEditor::config';

    public function __construct(
        Context $context,
        private readonly RawFactory $rawFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductCustomOptionInterfaceFactory $optionFactory,
        private readonly ProductCustomOptionValuesInterfaceFactory $optionValueFactory,
        private readonly ProductCustomOptionRepositoryInterface $optionRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): Raw
    {
        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'application/json; charset=utf-8');

        $body    = json_decode((string) $this->getRequest()->getContent(), true) ?: [];
        $prodId  = (int) ($body['productId'] ?? 0);
        $title   = trim((string) ($body['title'] ?? ''));
        $entries = (array) ($body['entries'] ?? []);

        if (!$prodId || $title === '' || empty($entries)) {
            $result->setHttpResponseCode(400);
            $result->setContents(json_encode(['success' => false, 'error' => 'Missing required parameters.']));
            return $result;
        }

        try {
            $product = $this->productRepository->getById($prodId);

            $values = [];
            foreach (array_values($entries) as $i => $entry) {
                $v = $this->optionValueFactory->create();
                $v->setTitle(trim((string) ($entry['label'] ?? $entry['key'] ?? '')));
                $v->setPrice(0.0);
                $v->setPriceType('fixed');
                $v->setSortOrder($i * 10);
                $values[] = $v;
            }

            $option = $this->optionFactory->create();
            $option->setProductSku($product->getSku());
            $option->setTitle($title);
            $option->setType('drop_down');
            $option->setIsRequire(false);
            $option->setSortOrder(0);
            $option->setValues($values);

            $this->optionRepository->save($option);

            $result->setContents(json_encode(['success' => true]));
        } catch (\Throwable $e) {
            $result->setHttpResponseCode(500);
            $result->setContents(json_encode(['success' => false, 'error' => $e->getMessage()]));
        }

        return $result;
    }
}
