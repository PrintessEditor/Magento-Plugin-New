<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Production;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderItemInterface;

/**
 * Resolves the productDefinitionId for an order item (child simple → parent product).
 * Dropshipping is configured per product only; there is no store-level default.
 *
 * Called at enqueue time; the result is snapshotted on the job so later product/config edits
 * do not change orders that are already in flight. See DropshipResolution for the return semantics.
 */
class DropshipResolver
{
    public const ATTR_MODE       = 'printess_dropship_mode';
    public const ATTR_DEFINITION = 'printess_dropship_product_definition';

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly DropshipResolution $resolution
    ) {
    }

    /** @return int|null null = no dropshipping, 0 = from template, >0 = explicit definition */
    public function resolve(OrderItemInterface $item, int $storeId): ?int
    {
        $levels = [];
        foreach ($this->getProductIds($item) as $productId) {
            $product = $this->loadProduct($productId, $storeId);
            if ($product === null) {
                continue;
            }
            $levels[] = [
                'mode'          => $this->toNullableString($product->getData(self::ATTR_MODE)),
                'definition_id' => $this->toNullableInt($product->getData(self::ATTR_DEFINITION)),
            ];
        }

        return $this->resolution->resolve($levels);
    }

    /**
     * Product ids ordered most specific first: for a configurable parent item, its child simple(s),
     * then the item's own product.
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

    /** A product deleted since the order was placed simply contributes no level. */
    private function loadProduct(int $productId, int $storeId): ?ProductInterface
    {
        try {
            return $this->productRepository->getById($productId, false, $storeId);
        } catch (NoSuchEntityException $e) {
            return null;
        }
    }

    private function toNullableString(mixed $value): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }
        $value = trim((string)$value);
        return $value !== '' ? $value : null;
    }

    private function toNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        $value = trim((string)$value);
        return preg_match('/^-?\d+$/', $value) ? (int)$value : null;
    }
}
