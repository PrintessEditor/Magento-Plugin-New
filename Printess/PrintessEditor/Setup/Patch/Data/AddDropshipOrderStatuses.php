<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;
use Printess\PrintessEditor\Model\Production\OrderStage;

/**
 * Registers the Printess production order statuses and assigns them to `new`/`processing` (never as default),
 * so they show up under Stores › Order Status and can be renamed there.
 *
 * insertOnDuplicate keeps it idempotent; existing labels are not overwritten so merchant renames survive.
 */
class AddDropshipOrderStatuses implements DataPatchInterface, PatchRevertableInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $setup
    ) {
    }

    public function apply(): self
    {
        $connection = $this->setup->getConnection();
        $connection->startSetup();

        $statusRows = [];
        $stateRows  = [];
        foreach (OrderStage::LABELS as $status => $label) {
            $statusRows[] = ['status' => $status, 'label' => $label];
            foreach (OrderStage::STATE_ASSIGNMENTS[$status] as $state) {
                $stateRows[] = [
                    'status'           => $status,
                    'state'            => $state,
                    'is_default'       => 0,
                    'visible_on_front' => 1,
                ];
            }
        }

        // Only `status` in the update list: re-running must not reset a merchant-edited label.
        $connection->insertOnDuplicate($this->setup->getTable('sales_order_status'), $statusRows, ['status']);
        $connection->insertOnDuplicate(
            $this->setup->getTable('sales_order_status_state'),
            $stateRows,
            ['is_default', 'visible_on_front']
        );

        $connection->endSetup();

        return $this;
    }

    public function revert(): void
    {
        $connection = $this->setup->getConnection();
        $connection->startSetup();
        $connection->delete(
            $this->setup->getTable('sales_order_status_state'),
            ['status IN (?)' => OrderStage::ALL]
        );
        $connection->delete(
            $this->setup->getTable('sales_order_status'),
            ['status IN (?)' => OrderStage::ALL]
        );
        $connection->endSetup();
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
