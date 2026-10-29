<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Production;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderItemInterface;
use Printess\PrintessEditor\Helper\Config;

/**
 * Resolves the Printess print settings name for an order item: child simple → parent product → store config.
 *
 * Resolved at enqueue time and snapshotted on the job, mirroring DropshipResolver.
 */
class PrintSettingsResolver
{
    public const ATTR_PRINT_SETTINGS = 'printess_print_settings';

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly Config $config
    ) {
    }

    public function resolve(OrderItemInterface $item, int $storeId): string
    {
        foreach ($this->getProductIds($item) as $productId) {
            try {
                $product = $this->productRepository->getById($productId, false, $storeId);
            } catch (NoSuchEntityException $e) {
                continue;
            }
            $value = trim((string)$product->getData(self::ATTR_PRINT_SETTINGS));
            if ($value !== '') {
                return $value;
            }
        }

        return $this->config->getPrintSettings($storeId);
    }

    /**
     * Product ids ordered most specific first: configurable child simple(s), then the item's own product.
     *
     * @return int[]
     */
    private function getProductIds(OrderItemInterface $item): array
    {
        $ids = [];
        // getChildrenItems() lives on the Order\Item model, not on the service interface.
        if (method_exists($item, 'getChildrenItems')) {
            foreach ((array)$item->getChildrenItems() as $child) {
                if ($child instanceof OrderItemInterface && $child->getProductId()) {
                    $ids[] = (int)$child->getProductId();
                }
            }
        }
        if ($item->getProductId()) {
            $ids[] = (int)$item->getProductId();
        }
        return array_values(array_unique($ids));
    }
}
