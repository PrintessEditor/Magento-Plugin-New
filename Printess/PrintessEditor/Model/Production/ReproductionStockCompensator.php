<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Production;

use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Psr\Log\LoggerInterface;

/**
 * Keeps the SALABLE stock unchanged for an order copy created with "Reduce stock" off (PMD-21).
 *
 * - Placement (compensate): MSI appended `order_placed` reservations (negative qty). This places the opposite
 *   reservations (positive qty) with the sales event type `printess_reproduction`, computed exactly like MSI's
 *   AppendReservationsAfterOrderPlacementPlugin (qty_ordered summed per product id, SKU by product id; product types
 *   without source items are skipped by PlaceReservationsForSalesEvent itself).
 *   Without MSI, legacy CatalogInventory never decremented stock: OrderReproductionService flags the quote
 *   `inventory_processed`, which SubtractQuoteInventoryObserver honours. Nothing to do here.
 * - Cancel (compensateCancel) and credit memo with "Return to Stock" (compensateRefund): Magento gives the cancelled /
 *   returned qty back (MSI: positive `order_canceled` / `creditmemo_created` reservations or source qty; legacy:
 *   StockManagement::backItemQty) although the copy never took it. This takes the same qty away again: MSI with a
 *   NEGATIVE reservation (`printess_reproduction_cancel` / `printess_reproduction_refund`); without MSI, mirroring the
 *   placement, by taking the qty off the legacy stock item (backItemQty with a negative qty).
 *
 * Shipping the copy still deducts the physical SOURCE qty as usual (the parcel really leaves the warehouse); only the
 * salable qty stays as it was before the copy.
 *
 * MSI and CatalogInventory classes are resolved at runtime through the object manager, so the module neither requires
 * nor breaks without Magento_InventorySales.
 */
class ReproductionStockCompensator
{
    public const SALES_EVENT_TYPE        = 'printess_reproduction';
    public const SALES_EVENT_TYPE_CANCEL = 'printess_reproduction_cancel';
    public const SALES_EVENT_TYPE_REFUND = 'printess_reproduction_refund';

    public const RESULT_MSI    = 'msi';
    public const RESULT_LEGACY = 'legacy';

    private const PLACE_RESERVATIONS = 'Magento\InventorySalesApi\Api\PlaceReservationsForSalesEventInterface';
    private const SKUS_BY_PRODUCT_IDS = 'Magento\InventoryCatalogApi\Model\GetSkusByProductIdsInterface';
    private const ITEM_TO_SELL_FACTORY = 'Magento\InventorySalesApi\Api\Data\ItemToSellInterfaceFactory';
    private const SALES_CHANNEL_FACTORY = 'Magento\InventorySalesApi\Api\Data\SalesChannelInterfaceFactory';
    private const SALES_EVENT_FACTORY = 'Magento\InventorySalesApi\Api\Data\SalesEventInterfaceFactory';
    private const SALES_EVENT_EXTENSION_FACTORY = 'Magento\InventorySalesApi\Api\Data\SalesEventExtensionFactory';
    private const LEGACY_STOCK_MANAGEMENT = 'Magento\CatalogInventory\Api\StockManagementInterface';

    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
        private readonly ModuleManager $moduleManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * True only for a copy whose `printess_reproduction_of` JSON says `stock_reduced: false`. Copies created before the
     * flag existed (no key) are left alone: whether their stock was compensated is unknown.
     */
    public static function isCopyWithoutStockReduction(mixed $reproductionOfJson): bool
    {
        if (!is_string($reproductionOfJson) || trim($reproductionOfJson) === '') {
            return false;
        }
        $data = json_decode($reproductionOfJson, true);
        return is_array($data) && array_key_exists('stock_reduced', $data) && $data['stock_reduced'] === false;
    }

    /**
     * Adds `stock_reduced` to a `printess_reproduction_of` JSON (ReproductionLink::buildReproductionOf), keeping
     * everything else as it is (an empty item_map stays an object).
     */
    public static function withStockReduced(string $reproductionOfJson, bool $stockReduced): string
    {
        $data = json_decode($reproductionOfJson, false, 512, JSON_THROW_ON_ERROR);
        if (!$data instanceof \stdClass) {
            throw new \InvalidArgumentException('printess_reproduction_of must be a JSON object');
        }
        $data->stock_reduced = $stockReduced;
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function isMsiAvailable(): bool
    {
        return $this->moduleManager->isEnabled('Magento_InventorySales')
            && interface_exists(self::PLACE_RESERVATIONS)
            && interface_exists(self::SKUS_BY_PRODUCT_IDS);
    }

    /**
     * Placement: gives back what the copy's `order_placed` reservations took.
     *
     * @return string RESULT_MSI when compensating reservations were placed, RESULT_LEGACY when MSI is not installed
     * @throws \Throwable when MSI is installed but placing the reservations failed
     */
    public function compensate(Order $order): string
    {
        if (!$this->isMsiAvailable()) {
            return self::RESULT_LEGACY;
        }
        $qtyByProductId = [];
        foreach ($order->getAllItems() as $item) {
            $this->add($qtyByProductId, (int) $item->getProductId(), (float) $item->getQtyOrdered());
        }
        $this->placeReservations($order, $qtyByProductId, self::SALES_EVENT_TYPE);
        return self::RESULT_MSI;
    }

    /**
     * After the copy was cancelled: takes away again the qty Magento gave back for the cancelled items.
     * Child items (configurable/bundle) are not cancelled themselves; they count with their parent's cancelled share.
     *
     * @throws \Throwable
     */
    public function compensateCancel(Order $order): string
    {
        $qtyByProductId = [];
        foreach ($order->getAllItems() as $item) {
            $parent = $item->getParentItem();
            if ($parent) {
                $parentOrdered = (float) $parent->getQtyOrdered();
                $qty = $parentOrdered > 0
                    ? (float) $item->getQtyOrdered() * (float) $parent->getQtyCanceled() / $parentOrdered
                    : 0.0;
            } else {
                $qty = (float) $item->getQtyCanceled();
            }
            $this->add($qtyByProductId, (int) $item->getProductId(), $qty);
        }
        return $this->takeBack($order, $qtyByProductId, self::SALES_EVENT_TYPE_CANCEL);
    }

    /**
     * After a credit memo of the copy: takes away again the qty of the items returned to stock.
     *
     * @throws \Throwable
     */
    public function compensateRefund(Order $order, Creditmemo $creditmemo): string
    {
        $backToStock = [];
        $items = $creditmemo->getAllItems();
        foreach ($items as $item) {
            if ($item->getBackToStock()) {
                $backToStock[(int) $item->getOrderItemId()] = true;
            }
        }
        $qtyByProductId = [];
        if ($backToStock !== []) {
            foreach ($items as $item) {
                $orderItem = $item->getOrderItem();
                $parentId = $orderItem ? (int) $orderItem->getParentItemId() : 0;
                if (!isset($backToStock[(int) $item->getOrderItemId()]) && !isset($backToStock[$parentId])) {
                    continue;
                }
                $productId = (int) ($item->getProductId() ?: ($orderItem ? $orderItem->getProductId() : 0));
                $this->add($qtyByProductId, $productId, (float) $item->getQty());
            }
        }
        return $this->takeBack($order, $qtyByProductId, self::SALES_EVENT_TYPE_REFUND);
    }

    /**
     * @param array<int, float> $qtyByProductId positive qty to take away
     */
    private function takeBack(Order $order, array $qtyByProductId, string $eventType): string
    {
        if ($this->isMsiAvailable()) {
            $negative = array_map(static fn (float $qty): float => -$qty, $qtyByProductId);
            $this->placeReservations($order, $negative, $eventType);
            return self::RESULT_MSI;
        }

        // Legacy CatalogInventory: the copy was placed `inventory_processed` (never decremented), but cancel and
        // return-to-stock call backItemQty. Mirror it with the opposite qty.
        if ($qtyByProductId !== [] && interface_exists(self::LEGACY_STOCK_MANAGEMENT)
            && $this->moduleManager->isEnabled('Magento_CatalogInventory')
        ) {
            $stockManagement = $this->objectManager->get(self::LEGACY_STOCK_MANAGEMENT);
            $websiteId = (int) $order->getStore()->getWebsiteId();
            foreach ($qtyByProductId as $productId => $qty) {
                $stockManagement->backItemQty($productId, -$qty, $websiteId);
            }
        }
        $this->logger->info('Printess: order copy without stock reduction, stock given back by Magento taken away again', [
            'order_id' => (int) $order->getEntityId(),
            'event'    => $eventType,
            'qty'      => $qtyByProductId,
            'msi'      => false,
        ]);
        return self::RESULT_LEGACY;
    }

    /**
     * @param array<int, float> $qtyByProductId
     */
    private function add(array &$qtyByProductId, int $productId, float $qty): void
    {
        if ($productId <= 0 || !($qty > 0)) {
            return;
        }
        $qtyByProductId[$productId] = ($qtyByProductId[$productId] ?? 0.0) + $qty;
    }

    /**
     * @param array<int, float> $qtyByProductId signed reservation qty per product id (0 is skipped)
     */
    private function placeReservations(Order $order, array $qtyByProductId, string $eventType): void
    {
        $qtyByProductId = array_filter($qtyByProductId, static fn (float $qty): bool => $qty != 0.0);
        if ($qtyByProductId === []) {
            return;
        }

        $skus = $this->objectManager->get(self::SKUS_BY_PRODUCT_IDS)->execute(array_keys($qtyByProductId));
        $itemFactory = $this->objectManager->get(self::ITEM_TO_SELL_FACTORY);
        $items = [];
        foreach ($skus as $productId => $sku) {
            $qty = $qtyByProductId[(int) $productId] ?? 0.0;
            if ($qty != 0.0) {
                $items[] = $itemFactory->create(['sku' => (string) $sku, 'qty' => $qty]);
            }
        }
        if ($items === []) {
            return;
        }

        $salesChannel = $this->objectManager->get(self::SALES_CHANNEL_FACTORY)->create([
            'data' => [
                'type' => 'website',
                'code' => (string) $order->getStore()->getWebsite()->getCode(),
            ],
        ]);
        $salesEvent = $this->objectManager->get(self::SALES_EVENT_FACTORY)->create([
            'type'       => $eventType,
            'objectType' => 'order',
            'objectId'   => (string) $order->getEntityId(),
        ]);
        $salesEvent->setExtensionAttributes(
            $this->objectManager->get(self::SALES_EVENT_EXTENSION_FACTORY)->create([
                'data' => ['objectIncrementId' => (string) $order->getIncrementId()],
            ])
        );

        $this->objectManager->get(self::PLACE_RESERVATIONS)->execute($items, $salesChannel, $salesEvent);
    }
}
