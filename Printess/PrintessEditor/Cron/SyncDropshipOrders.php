<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Cron;

use Magento\Framework\Lock\LockManagerInterface;
use Printess\PrintessEditor\Model\Production\DropshipOrderSync;
use Psr\Log\LoggerInterface;

/**
 * Dropship poller (every 30 minutes, see crontab.xml): pulls partner states and tracking numbers from
 * /dropship/orders/list for open dropship jobs. Locked so a slow run (many pages) never overlaps the next one.
 */
class SyncDropshipOrders
{
    private const LOCK_NAME = 'printess_sync_dropship_orders';

    public function __construct(
        private readonly DropshipOrderSync $dropshipOrderSync,
        private readonly LockManagerInterface $lockManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->lockManager->lock(self::LOCK_NAME, 0)) {
            return; // previous run still working
        }

        try {
            $changed = $this->dropshipOrderSync->syncOpenJobs();
            if ($changed > 0) {
                $this->logger->info(sprintf('Printess dropship sync: %d job(s) updated.', $changed));
            }
        } catch (\Throwable $e) {
            $this->logger->error('Printess dropship sync failed: ' . $e->getMessage(), ['exception' => $e]);
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }
}
