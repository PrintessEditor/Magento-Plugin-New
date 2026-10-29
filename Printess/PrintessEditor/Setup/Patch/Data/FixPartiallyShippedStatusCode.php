<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Printess\PrintessEditor\Model\Production\OrderStage;

/**
 * The first status code for "Partially shipped" (`printess_dropship_partially_shipped`, 35 chars) was longer than
 * sales_order_status.status (varchar 32) and was stored cut off as `printess_dropship_partially_ship`, so the
 * module could never set it. The code is now OrderStage::PARTIALLY_SHIPPED (`printess_dropship_part_shipped`).
 *
 * This patch adds the new status (via the same insert-only logic as AddDropshipOrderStatuses), moves any orders and
 * labels from the cut-off code to it, and removes the cut-off status.
 */
class FixPartiallyShippedStatusCode implements DataPatchInterface
{
    private const TRUNCATED = 'printess_dropship_partially_ship';

    public function __construct(
        private readonly ModuleDataSetupInterface $setup
    ) {
    }

    public function apply(): self
    {
        $connection = $this->setup->getConnection();
        $connection->startSetup();

        $statusTable = $this->setup->getTable('sales_order_status');
        $stateTable  = $this->setup->getTable('sales_order_status_state');
        $labelTable  = $this->setup->getTable('sales_order_status_label');
        $new         = OrderStage::PARTIALLY_SHIPPED;

        // 1. The new status and its state assignments (insert-only: a merchant-edited label is kept).
        $existingLabel = $connection->fetchOne(
            $connection->select()->from($statusTable, ['label'])->where('status = ?', self::TRUNCATED)
        );
        $connection->insertOnDuplicate(
            $statusTable,
            [['status' => $new, 'label' => $existingLabel ?: OrderStage::LABELS[$new]]],
            ['status']
        );
        $stateRows = [];
        foreach (OrderStage::STATE_ASSIGNMENTS[$new] as $state) {
            $stateRows[] = ['status' => $new, 'state' => $state, 'is_default' => 0, 'visible_on_front' => 1];
        }
        $connection->insertOnDuplicate($stateTable, $stateRows, ['is_default', 'visible_on_front']);

        // 2. Move what used the cut-off code (orders and their grid rows, per-store labels), then remove it.
        foreach (['sales_order', 'sales_order_grid'] as $table) {
            $connection->update($this->setup->getTable($table), ['status' => $new], ['status = ?' => self::TRUNCATED]);
        }
        $labels = $connection->fetchAll(
            $connection->select()->from($labelTable, ['store_id', 'label'])->where('status = ?', self::TRUNCATED)
        );
        if ($labels !== []) {
            $rows = array_map(static fn (array $l) => ['status' => $new, 'store_id' => (int) $l['store_id'], 'label' => $l['label']], $labels);
            $connection->insertOnDuplicate($labelTable, $rows, ['status', 'store_id']);
        }
        $connection->delete($labelTable, ['status = ?' => self::TRUNCATED]);
        $connection->delete($stateTable, ['status = ?' => self::TRUNCATED]);
        $connection->delete($statusTable, ['status = ?' => self::TRUNCATED]);

        $connection->endSetup();

        return $this;
    }

    public static function getDependencies(): array
    {
        return [AddDropshipOrderStatuses::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
