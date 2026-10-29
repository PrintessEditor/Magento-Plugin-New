<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Production;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Sales\Api\OrderItemRepositoryInterface;
use Printess\PrintessEditor\Model\PdfJob;
use Printess\PrintessEditor\Model\PdfJobRepository;
use Psr\Log\LoggerInterface;

/**
 * Applies a Printess rendering result to a job. The payload is either the rendering callback body
 * (isFailure/failureDetails, no isFinalStatus) or a /production/status/get response
 * (isFinalStatus, isSuccess/errorDetails); both namings are accepted, as in the Shopware plugin.
 *
 * Print jobs: every output file (PDF, images or ZIP, see ProductionResultFiles) is downloaded to var/ and the
 * job is complete.
 * Dropship jobs: the dropshipper got the file from Printess, so only the result is stored (rendered).
 * Only jobs in `producing` are touched, which makes this idempotent (callbacks are retried and may race the
 * cron poll). Never throws.
 */
class RenderResultHandler
{
    public function __construct(
        private readonly PdfJobRepository $pdfJobRepository,
        private readonly OrderStageUpdater $stageUpdater,
        private readonly OrderItemRepositoryInterface $orderItemRepository,
        private readonly Filesystem $filesystem,
        private readonly LoggerInterface $logger
    ) {
    }

    public function apply(PdfJob $job, array $payload): void
    {
        try {
            if (array_key_exists('isFinalStatus', $payload) && $payload['isFinalStatus'] === false) {
                return; // still rendering
            }
            $status = (string) $job->getData('status');
            if ($status !== PdfJob::STATUS_PRODUCING) {
                // Only `producing` awaits a render result. Anything else (finished, partner-failed, reset for a
                // retry, ...) must not be revived by a late callback or an admin "Check status" re-read.
                $this->logger->debug('Printess: rendering result ignored, job is not producing', [
                    'job_id' => $job->getId(), 'status' => $status,
                ]);
                return;
            }

            $orderIdAtPrintess = $payload['orderId'] ?? null;
            if ((string) $job->getData('printess_order_id') === '' && is_scalar($orderIdAtPrintess) && (string) $orderIdAtPrintess !== '') {
                $job->setData('printess_order_id', (string) $orderIdAtPrintess);
            }

            if (!$this->isSuccess($payload)) {
                $this->fail($job, 'render_failed', $this->failureMessage($payload));
                return;
            }

            $job->setData('result_json', $this->encode($payload['result'] ?? null));

            if ($job->isDropship()) {
                $this->applyDropshipSuccess($job);
            } else {
                $this->applyPrintSuccess($job, $payload);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Printess: could not apply rendering result', [
                'job_id' => $job->getId(), 'error' => $e->getMessage(), 'exception' => $e,
            ]);
        }
    }

    private function applyDropshipSuccess(PdfJob $job): void
    {
        $job->setData('status', PdfJob::STATUS_RENDERED);
        $job->setData('error_code', null);
        $job->setData('error_message', null);
        $job->appendHistory(['status' => PdfJob::STATUS_RENDERED]);
        $this->pdfJobRepository->save($job);

        $this->stageUpdater->update(
            (int) $job->getData('order_id'),
            sprintf('Printess: item %s rendered and sent to the dropshipper', $this->sku($job))
        );
    }

    private function applyPrintSuccess(PdfJob $job, array $payload): void
    {
        $files = ProductionResultFiles::fromResult($payload['result'] ?? null);
        if ($files === []) {
            $this->fail($job, 'no_output', 'Printess job succeeded but the result contained no output files (PDF, images or ZIP)');
            return;
        }

        $orderId = (int) $job->getData('order_id');
        $itemId  = (int) $job->getData('order_item_id');

        try {
            $paths = $this->saveFiles($files, $orderId, $itemId);
        } catch (\Throwable $e) {
            // Rendering succeeded; only the download failed. Keep `producing` so the next poll retries.
            $failCount = (int) $job->getData('fail_count') + 1;
            $job->setData('fail_count', $failCount);
            if ($failCount >= PdfJob::MAX_FAIL_COUNT) {
                $this->fail($job, 'download_failed', $e->getMessage());
                return;
            }
            $job->setData('error_code', 'download_failed');
            $job->setData('error_message', $e->getMessage());
            $job->appendHistory(['event' => 'download_failed', 'fail_count' => $failCount, 'error' => $e->getMessage()]);
            $this->pdfJobRepository->save($job);
            $this->logger->warning('Printess: output download failed (will retry)', [
                'job_id' => $job->getId(), 'fail_count' => $failCount, 'error' => $e->getMessage(),
            ]);
            return;
        }

        // pdf_path keeps the first file (column name from the PDF-only era); the history has all of them.
        $job->setData('status', PdfJob::STATUS_COMPLETE);
        $job->setData('pdf_path', $paths[0]);
        $job->setData('error_code', null);
        $job->setData('error_message', null);
        $job->appendHistory(['status' => PdfJob::STATUS_COMPLETE, 'files' => $paths]);
        $this->pdfJobRepository->save($job);

        $this->addOrderComment(
            $orderId,
            $job,
            count($paths) === 1
                ? 'Printess output ready: var/' . $paths[0]
                : 'Printess output ready (' . count($paths) . ' files): var/' . implode(', var/', $paths)
        );

        $this->logger->info('Printess: production output saved', [
            'job_id' => $job->getId(), 'order_id' => $orderId, 'files' => $paths,
        ]);
    }

    private function fail(PdfJob $job, string $code, string $message): void
    {
        $job->setData('status', PdfJob::STATUS_FAILED);
        $job->setData('error_code', $code);
        $job->setData('error_message', $message);
        $job->appendHistory(['status' => PdfJob::STATUS_FAILED, 'error_code' => $code, 'error' => $message]);
        $this->pdfJobRepository->save($job);

        $this->logger->error('Printess: rendering failed', [
            'job_id' => $job->getId(), 'order_id' => $job->getData('order_id'), 'error' => $message,
        ]);

        $this->stageUpdater->update(
            (int) $job->getData('order_id'),
            sprintf('Printess: production of item %s failed: %s', $this->sku($job), $message)
        );
    }

    private function isSuccess(array $payload): bool
    {
        if (array_key_exists('isFailure', $payload)) {
            return !$payload['isFailure'];
        }
        if (array_key_exists('isSuccess', $payload)) {
            return (bool) $payload['isSuccess'];
        }
        return is_array($payload['result'] ?? null);
    }

    private function failureMessage(array $payload): string
    {
        $details = $payload['failureDetails'] ?? $payload['errorDetails'] ?? null;
        if (is_string($details) && trim($details) !== '') {
            return trim($details);
        }
        if ($details !== null && $details !== [] && $details !== '') {
            return $this->encode($details) ?? 'Printess reported a production failure.';
        }
        return 'Printess reported a production failure.';
    }

    /**
     * Downloads every output file to var/printess/order_<id>/. One file keeps the historical name
     * item_<itemId>.<ext>; several are numbered item_<itemId>_<n>.<ext>. All files are downloaded before
     * anything is written, so a failed download leaves no partial set behind (the next poll retries).
     *
     * @param list<array{type: string, document: string, index: int, url: string, extension: string}> $files
     * @return list<string> paths relative to var/
     */
    private function saveFiles(array $files, int $orderId, int $itemId): array
    {
        // PR #8 hardening: https only, certificate checked, transport errors thrown instead of suppressed.
        $ctx = stream_context_create([
            'http' => ['timeout' => 60],
            'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $contents = [];
        foreach ($files as $file) {
            $url = (string) $file['url'];
            if (!str_starts_with(strtolower($url), 'https://')) {
                throw new \RuntimeException('Refusing to download production file from non-HTTPS URL: ' . $url);
            }
            set_error_handler(static function (int $errno, string $errstr) use ($url): never {
                throw new \RuntimeException('Could not download production file from ' . $url . ': ' . $errstr, $errno);
            });
            try {
                $content = file_get_contents($url, false, $ctx);
            } finally {
                restore_error_handler();
            }
            if ($content === false || $content === '') {
                throw new \RuntimeException('Could not download production file from ' . $url);
            }
            $contents[] = $content;
        }

        $varDir = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $dir    = 'printess/order_' . $orderId;
        $varDir->create($dir);

        $paths = [];
        $single = count($files) === 1;
        foreach ($files as $n => $file) {
            $path = $dir . '/item_' . $itemId . ($single ? '' : '_' . ($n + 1)) . '.' . $file['extension'];
            $varDir->writeFile($path, $contents[$n]);
            $paths[] = $path;
        }
        return $paths;
    }

    /**
     * Print jobs do not drive an order status, so the "PDF ready" note is only a comment (as before), written
     * regardless of the order state and without a full order save.
     */
    private function addOrderComment(int $orderId, PdfJob $job, string $comment): void
    {
        $this->stageUpdater->addComment($orderId, 'Printess [item ' . $this->sku($job) . ']: ' . $comment);
    }

    private function sku(PdfJob $job): string
    {
        $itemId = (int) $job->getData('order_item_id');
        try {
            $sku = (string) $this->orderItemRepository->get($itemId)->getSku();
            return $sku !== '' ? $sku : '#' . $itemId;
        } catch (\Throwable) {
            return '#' . $itemId;
        }
    }

    private function encode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return $json === false ? null : $json;
    }
}
