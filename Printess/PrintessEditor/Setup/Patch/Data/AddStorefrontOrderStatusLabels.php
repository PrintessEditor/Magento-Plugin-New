<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Printess\PrintessEditor\Model\Production\OrderStage;

/**
 * Gives the Printess order statuses customer-facing labels on every store view (sales_order_status_label), so the
 * storefront shows e.g. "In production" instead of "Printess: Sent to dropshipper". The admin keeps the
 * "Printess: …" names.
 *
 * Labels a merchant already set for a store view are kept (insert-only). Store views created later get no label and
 * fall back to the admin name; set one under Stores › Order Status if needed.
 */
class AddStorefrontOrderStatusLabels implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $setup
    ) {
    }

    public function apply(): self
    {
        $connection = $this->setup->getConnection();
        $connection->startSetup();

        $storeIds = $connection->fetchCol(
            $connection->select()
                ->from($this->setup->getTable('store'), ['store_id'])
                ->where('store_id > ?', 0)
        );

        $rows = [];
        foreach ($storeIds as $storeId) {
            foreach (OrderStage::STOREFRONT_LABELS as $status => $label) {
                $rows[] = ['status' => $status, 'store_id' => (int) $storeId, 'label' => $label];
            }
        }

        if ($rows !== []) {
            // Only the key columns in the update list: an existing (merchant) label is never overwritten.
            $connection->insertOnDuplicate(
                $this->setup->getTable('sales_order_status_label'),
                $rows,
                ['status', 'store_id']
            );
        }

        $connection->endSetup();

        return $this;
    }

    public static function getDependencies(): array
    {
        return [AddDropshipOrderStatuses::class, FixPartiallyShippedStatusCode::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
