<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Printess\PrintessEditor\Model\PdfJob;

/**
 * Dropship jobs sent before the send counter existed have `dropship_send_count` 0, so their first restart would
 * compute send 1 and send the bare increment id again — which dropshippers like Siteflow refuse. Every dropship job
 * that was already submitted (submitted_at, printess_job_id or printess_order_id set) and still has send count 0 is
 * marked as send 1 with the order's increment id as its shop order id; a missing callback_type is set to what those
 * sends used (1 legacy callback for callback mode, 0 otherwise).
 *
 * Idempotent: only rows with send count 0 / callback_type NULL are touched, and an existing shop order id is kept.
 */
class BackfillDropshipSendCount implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $setup
    ) {
    }

    public function apply(): self
    {
        $connection = $this->setup->getConnection();
        $jobsTable  = $this->setup->getTable('printess_pdf_jobs');
        $orderTable = $this->setup->getTable('sales_order');

        if (!$connection->isTableExists($jobsTable)) {
            return $this;
        }

        $connection->startSetup();
        try {
            $submitted = '(j.submitted_at IS NOT NULL OR j.printess_job_id IS NOT NULL OR j.printess_order_id IS NOT NULL)';

            // callback_type first (it does not depend on the send count), so a rerun after a partial run is complete.
            $connection->update(
                $jobsTable,
                ['callback_type' => new \Magento\Framework\DB\Sql\Expression(sprintf(
                    "CASE WHEN callback_mode = %s THEN %d ELSE %d END",
                    $connection->quote(PdfJob::CALLBACK_MODE_CALLBACK),
                    PdfJob::DROPSHIP_CALLBACK_TYPE_LEGACY,
                    PdfJob::DROPSHIP_CALLBACK_TYPE_NONE
                ))],
                [
                    'production_type = ?' => PdfJob::TYPE_DROPSHIP,
                    'callback_type IS NULL',
                    '(submitted_at IS NOT NULL OR printess_job_id IS NOT NULL OR printess_order_id IS NOT NULL)',
                ]
            );

            $select = $connection->select()
                ->from(['j' => $jobsTable], ['job_id', 'dropship_shop_order_id'])
                ->joinLeft(['o' => $orderTable], 'o.entity_id = j.order_id', ['increment_id'])
                ->where('j.production_type = ?', PdfJob::TYPE_DROPSHIP)
                ->where('j.dropship_send_count = 0')
                ->where($submitted)
                ->order('j.job_id ASC');
            $rows = $connection->fetchAll($select);

            foreach ($rows as $row) {
                $data = ['dropship_send_count' => 1];
                $incrementId = trim((string) ($row['increment_id'] ?? ''));
                if (trim((string) ($row['dropship_shop_order_id'] ?? '')) === '' && $incrementId !== '') {
                    $data['dropship_shop_order_id'] = $incrementId;
                }
                $connection->update(
                    $jobsTable,
                    $data,
                    ['job_id = ?' => (int) $row['job_id'], 'dropship_send_count = 0']
                );
            }
        } finally {
            $connection->endSetup();
        }

        return $this;
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
