<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Cron;

use Magento\Framework\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Printess\PrintessEditor\Helper\Config;
use Printess\PrintessEditor\Model\PdfJob;
use Printess\PrintessEditor\Model\PrintessApi;
use Printess\PrintessEditor\Model\ResourceModel\PdfJob as PdfJobResource;
use Printess\PrintessEditor\Model\ResourceModel\PdfJob\CollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Processes the printess_pdf_jobs queue in two phases each cron run:
 *
 *  Phase 1 — pending jobs:
 *    Call produce() to submit the job to the Printess API.
 *    Store the returned printess_job_id and advance status to 'producing'.
 *
 *  Phase 2 — producing jobs:
 *    Call getJobStatus() once (no polling — the next cron run will re-check).
 *    If the job is finished successfully, download the PDF and mark 'complete'.
 *    If the job failed, increment fail_count.  Once fail_count >= MAX_FAIL_COUNT
 *    the job is marked 'failed' and will not be retried.
 *
 * Runs every 5 minutes via crontab.xml.
 */
class ProducePdf
{
    private const LOCK_NAME  = 'printess_produce_pdf';
    private const BATCH_SIZE = 50;

    public function __construct(
        private readonly Config $config,
        private readonly CollectionFactory $collectionFactory,
        private readonly PdfJobResource $pdfJobResource,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly Filesystem $filesystem,
        private readonly LockManagerInterface $lockManager,
        private readonly LoggerInterface $logger
    ) {}

    public function execute(): void
    {
        if (!$this->lockManager->lock(self::LOCK_NAME, 0)) {
            return; // another cron run is still working
        }

        try {
            $serviceToken = $this->config->getServiceToken();
            if (empty($serviceToken)) {
                $this->logger->warning('Printess: no service token configured — PDF production cron skipped.');
                return;
            }

            $api = new PrintessApi($serviceToken);

            $this->processPendingJobs($api);
            $this->processProducingJobs($api);
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }

    /**
     * Phase 1: submit pending jobs to the Printess production API.
     */
    private function processPendingJobs(PrintessApi $api): void
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', PdfJob::STATUS_PENDING);
        $collection->setPageSize(self::BATCH_SIZE);

        $printSettings = $this->config->getPrintSettings();

        foreach ($collection as $job) {
            try {
                $printessJobId = $api->produce(
                    (string) $job->getData('save_token'),
                    (string) $job->getData('order_id'),
                    $printSettings
                );

                $job->setData('printess_job_id', $printessJobId);
                $job->setData('status', PdfJob::STATUS_PRODUCING);
                $this->pdfJobResource->save($job);

                $this->logger->info('Printess: submitted PDF job', [
                    'job_id'           => $job->getId(),
                    'order_id'         => $job->getData('order_id'),
                    'printess_job_id'  => $printessJobId,
                ]);
            } catch (\Throwable $e) {
                $this->recordFailure($job, 'produce() failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Phase 2: check in-progress jobs and finalise completed ones.
     */
    private function processProducingJobs(PrintessApi $api): void
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', PdfJob::STATUS_PRODUCING);
        $collection->setPageSize(self::BATCH_SIZE);

        foreach ($collection as $job) {
            $printessJobId = (string) $job->getData('printess_job_id');
            if ($printessJobId === '') {
                // Should not happen, but guard against it.
                $this->recordFailure($job, 'producing job has no printess_job_id');
                continue;
            }

            try {
                $status = $api->getJobStatus($printessJobId);

                if (empty($status->isFinalStatus) || $status->isFinalStatus !== true) {
                    continue; // still in progress — check again next cron run
                }

                if (empty($status->isSuccess) || $status->isSuccess !== true) {
                    $this->recordFailure(
                        $job,
                        'Printess production failed: ' . json_encode($status->errorDetails ?? 'unknown error')
                    );
                    continue;
                }

                // Job succeeded — find the PDF URL in the result.
                $pdfUrl = null;
                foreach ((array) ($status->result->r ?? []) as $url) {
                    $pdfUrl = $url;
                    break;
                }

                if ($pdfUrl === null) {
                    $this->recordFailure($job, 'Printess job succeeded but result contained no PDF URL');
                    continue;
                }

                $filePath = $this->savePdf($pdfUrl, (int) $job->getData('order_id'), (int) $job->getData('order_item_id'));

                $job->setData('status', PdfJob::STATUS_COMPLETE);
                $job->setData('pdf_path', $filePath);
                $this->pdfJobResource->save($job);

                $this->addOrderComment(
                    (int) $job->getData('order_id'),
                    (int) $job->getData('order_item_id'),
                    'Printess PDF ready: var/' . $filePath
                );

                $this->logger->info('Printess: PDF production complete', [
                    'job_id'    => $job->getId(),
                    'order_id'  => $job->getData('order_id'),
                    'pdf_path'  => $filePath,
                ]);
            } catch (\Throwable $e) {
                $this->recordFailure($job, 'status check failed: ' . $e->getMessage());
            }
        }
    }

    private function recordFailure(PdfJob $job, string $reason): void
    {
        $failCount = (int) $job->getData('fail_count') + 1;
        $job->setData('fail_count', $failCount);

        if ($failCount >= PdfJob::MAX_FAIL_COUNT) {
            $job->setData('status', PdfJob::STATUS_FAILED);
            $this->addOrderComment(
                (int) $job->getData('order_id'),
                (int) $job->getData('order_item_id'),
                'Printess PDF production failed (gave up after ' . $failCount . ' attempts): ' . $reason
            );
            $this->logger->error('Printess: PDF job permanently failed', [
                'job_id'   => $job->getId(),
                'order_id' => $job->getData('order_id'),
                'reason'   => $reason,
            ]);
        } else {
            // Leave status as-is — will retry on the next cron run.
            $this->logger->warning('Printess: PDF job attempt failed (will retry)', [
                'job_id'     => $job->getId(),
                'order_id'   => $job->getData('order_id'),
                'fail_count' => $failCount,
                'reason'     => $reason,
            ]);
        }

        try {
            $this->pdfJobResource->save($job);
        } catch (\Throwable $e) {
            $this->logger->error('Printess: could not save PDF job failure record', [
                'job_id' => $job->getId(),
                'error'  => $e->getMessage(),
            ]);
        }
    }

    private function savePdf(string $pdfUrl, int $orderId, int $itemId): string
    {
        $varDir   = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $dir      = 'printess/order_' . $orderId;
        $filePath = $dir . '/item_' . $itemId . '.pdf';

        $varDir->create($dir);

        $content = @file_get_contents($pdfUrl);
        if ($content === false) {
            throw new \RuntimeException('Could not download PDF from ' . $pdfUrl);
        }

        $varDir->writeFile($filePath, $content);

        return $filePath;
    }

    private function addOrderComment(int $orderId, int $itemId, string $comment): void
    {
        try {
            $order = $this->orderRepository->get($orderId);
            $order->addCommentToStatusHistory('Printess [item #' . $itemId . ']: ' . $comment);
            $this->orderRepository->save($order);
        } catch (\Throwable $e) {
            $this->logger->warning('Printess: could not add order comment', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
