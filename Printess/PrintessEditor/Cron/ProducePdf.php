<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Cron;

use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Printess\PrintessEditor\Model\PdfJob;
use Printess\PrintessEditor\Model\PdfJobRepository;
use Printess\PrintessEditor\Model\PrintessApiException;
use Printess\PrintessEditor\Model\PrintessApiProvider;
use Printess\PrintessEditor\Model\Production\JobSubmitter;
use Printess\PrintessEditor\Model\Production\OrderStageUpdater;
use Printess\PrintessEditor\Model\Production\PaymentReleaser;
use Printess\PrintessEditor\Model\Production\RenderResultHandler;
use Printess\PrintessEditor\Model\ResourceModel\PdfJob\Collection;
use Printess\PrintessEditor\Model\ResourceModel\PdfJob\CollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates the printess_pdf_jobs queue every cron run (every 5 minutes, see crontab.xml):
 *
 *  1. release   — paid awaiting_payment jobs move on (PaymentReleaser).
 *  2. stale     — dropship jobs stuck in `submitting` > 10 min died mid-request: their outcome is unknown,
 *                 so they are failed for an admin to check instead of being resent (duplicate shipment).
 *  3. submit    — pending jobs are sent to Printess (JobSubmitter): print jobs one by one, dropship jobs as one
 *                 group per order.
 *  4. poll      — producing jobs are checked via /production/status/get: always in poll mode, and in
 *                 callback mode only as a fallback when no callback arrived within 2 hours.
 *
 * Service tokens are per store; jobs of stores without one are skipped with a warning.
 */
class ProducePdf
{
    private const LOCK_NAME          = 'printess_produce_pdf';
    private const BATCH_SIZE         = 50;
    private const STALE_SUBMIT_SECS  = 600;
    private const CALLBACK_WAIT_SECS = 7200;

    /** @var array<int, bool> store id => has token, per run */
    private array $tokenCache = [];

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly PdfJobRepository $pdfJobRepository,
        private readonly PaymentReleaser $paymentReleaser,
        private readonly JobSubmitter $jobSubmitter,
        private readonly RenderResultHandler $renderResultHandler,
        private readonly OrderStageUpdater $stageUpdater,
        private readonly PrintessApiProvider $apiProvider,
        private readonly StoreManagerInterface $storeManager,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LockManagerInterface $lockManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->lockManager->lock(self::LOCK_NAME, 0)) {
            return; // another cron run is still working
        }

        $this->tokenCache = [];

        try {
            $this->runStep('release', fn () => $this->paymentReleaser->releasePaidJobs());
            $this->runStep('stale sweep', fn () => $this->sweepStaleSubmissions());
            $this->runStep('submit', fn () => $this->submitPendingJobs());
            $this->runStep('poll', fn () => $this->pollProducingJobs());
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }

    /** One failing step must not stop the others. */
    private function runStep(string $name, callable $step): void
    {
        try {
            $step();
        } catch (\Throwable $e) {
            $this->logger->error('Printess: production cron step "' . $name . '" failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }

    private function sweepStaleSubmissions(): void
    {
        $cutoff     = gmdate('Y-m-d H:i:s', time() - self::STALE_SUBMIT_SECS);
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', PdfJob::STATUS_SUBMITTING);
        $collection->addFieldToFilter('submitted_at', [['lt' => $cutoff], ['null' => true]]);
        $collection->setOrder('job_id', Collection::SORT_ORDER_ASC);
        $collection->setPageSize(self::BATCH_SIZE);

        /** @var PdfJob $job */
        foreach ($collection as $job) {
            try {
                $message = 'Dropship submission was interrupted; it may or may not have reached Printess.';
                $job->setData('status', PdfJob::STATUS_FAILED);
                $job->setData('error_code', 'outcome_unknown');
                $job->setData('error_message', $message);
                $job->appendHistory(['status' => PdfJob::STATUS_FAILED, 'error_code' => 'outcome_unknown', 'error' => $message]);
                $this->pdfJobRepository->save($job);

                $this->logger->error('Printess: stale dropship submission flagged', ['job_id' => $job->getId()]);

                $this->stageUpdater->update(
                    (int) $job->getData('order_id'),
                    sprintf(
                        'Printess: the dropship submission of item #%d was interrupted and its outcome is unknown. '
                        . 'Check the Printess order list before restarting it, otherwise the item may be produced and shipped twice.',
                        (int) $job->getData('order_item_id')
                    )
                );
            } catch (\Throwable $e) {
                $this->logger->error('Printess: could not flag stale submission', [
                    'job_id' => $job->getId(), 'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function submitPendingJobs(): void
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', PdfJob::STATUS_PENDING);
        $this->excludeStoresWithoutToken($collection);
        $collection->setOrder('job_id', Collection::SORT_ORDER_ASC);
        $collection->setPageSize(self::BATCH_SIZE);

        // Dropship jobs are sent as one group per order (never item by item, see JobSubmitter::submitDropshipGroup);
        // print jobs one by one.
        $dropshipOrders = [];
        /** @var PdfJob $job */
        foreach ($collection as $job) {
            if ($job->isDropship()) {
                $dropshipOrders[(int) $job->getData('order_id')] = true;
                continue;
            }
            $this->jobSubmitter->submit($job); // never throws
        }

        foreach (array_keys($dropshipOrders) as $orderId) {
            // Re-reads every pending dropship job of the order, so the page size can never split a group.
            $this->jobSubmitter->submitDropshipGroup($orderId); // never throws
        }
    }

    private function pollProducingJobs(): void
    {
        $cutoff     = gmdate('Y-m-d H:i:s', time() - self::CALLBACK_WAIT_SECS);
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', PdfJob::STATUS_PRODUCING);
        // OR across fields: poll mode, or a callback that is overdue (or of unknown age).
        $collection->addFieldToFilter(
            ['callback_mode', 'submitted_at', 'submitted_at'],
            [['neq' => PdfJob::CALLBACK_MODE_CALLBACK], ['lt' => $cutoff], ['null' => true]]
        );
        $this->excludeStoresWithoutToken($collection);
        $collection->setOrder('job_id', Collection::SORT_ORDER_ASC);
        $collection->setPageSize(self::BATCH_SIZE);

        /** @var PdfJob $job */
        foreach ($collection as $job) {
            $this->pollJob($job);
        }
    }

    private function pollJob(PdfJob $job): void
    {
        $printessJobId = (string) $job->getData('printess_job_id');
        if ($printessJobId === '') {
            $this->failPolledJob($job, 'no_job_id', 'Producing job has no Printess job id.');
            return;
        }

        $storeId = $this->storeIdOf($job);
        if ($storeId === null || !$this->hasToken($storeId)) {
            return;
        }

        try {
            $status = $this->apiProvider->forStore($storeId)->getJobStatusArray($printessJobId);
        } catch (PrintessApiException $e) {
            if ($e->isPermanent()) {
                $this->failPolledJob($job, 'http_' . $e->getHttpStatus(), $e->getPrintessMessage() ?? $e->getMessage());
                return;
            }
            $this->logger->warning('Printess: status check failed (will retry next run)', [
                'job_id' => $job->getId(), 'error' => $e->getMessage(),
            ]);
            return;
        } catch (\Throwable $e) {
            $this->logger->warning('Printess: status check failed (will retry next run)', [
                'job_id' => $job->getId(), 'error' => $e->getMessage(),
            ]);
            return;
        }

        $this->renderResultHandler->apply($job, $status); // never throws
    }

    private function failPolledJob(PdfJob $job, string $code, string $message): void
    {
        try {
            $job->setData('status', PdfJob::STATUS_FAILED);
            $job->setData('error_code', $code);
            $job->setData('error_message', $message);
            $job->appendHistory(['status' => PdfJob::STATUS_FAILED, 'error_code' => $code, 'error' => $message]);
            $this->pdfJobRepository->save($job);
        } catch (\Throwable $e) {
            $this->logger->error('Printess: could not save failed job', ['job_id' => $job->getId(), 'error' => $e->getMessage()]);
            return;
        }

        $this->stageUpdater->update(
            (int) $job->getData('order_id'),
            sprintf('Printess: production of item #%d failed (%s): %s', (int) $job->getData('order_item_id'), $code, $message)
        );
    }

    /**
     * Rows without store_id (created before the upgrade) are kept; JobSubmitter / storeIdOf() resolve them.
     */
    private function excludeStoresWithoutToken(Collection $collection): void
    {
        $without = [];
        foreach ($this->storeManager->getStores(true) as $store) {
            $storeId = (int) $store->getId();
            if (!$this->hasToken($storeId)) {
                $without[] = $storeId;
            }
        }

        if ($without !== []) {
            $collection->addFieldToFilter('store_id', [['nin' => $without], ['null' => true]]);
        }
    }

    private function hasToken(int $storeId): bool
    {
        if (!isset($this->tokenCache[$storeId])) {
            $this->tokenCache[$storeId] = $this->apiProvider->hasServiceToken($storeId);
            if (!$this->tokenCache[$storeId] && $storeId !== 0) {
                $this->logger->warning('Printess: no service token configured for store ' . $storeId
                    . ' — its production jobs are skipped.');
            }
        }
        return $this->tokenCache[$storeId];
    }

    private function storeIdOf(PdfJob $job): ?int
    {
        $storeId = $job->getData('store_id');
        if ($storeId !== null && $storeId !== '') {
            return (int) $storeId;
        }

        try {
            return (int) $this->orderRepository->get((int) $job->getData('order_id'))->getStoreId();
        } catch (\Throwable $e) {
            $this->logger->warning('Printess: could not resolve store of job', [
                'job_id' => $job->getId(), 'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
