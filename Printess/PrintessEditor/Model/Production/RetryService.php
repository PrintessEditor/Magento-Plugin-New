<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Production;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Store\Model\StoreManagerInterface;
use Printess\PrintessEditor\Helper\Config;
use Printess\PrintessEditor\Model\PdfJob;
use Printess\PrintessEditor\Model\PdfJobRepository;
use Printess\PrintessEditor\Model\PrintessApiException;
use Printess\PrintessEditor\Model\PrintessApiProvider;
use Psr\Log\LoggerInterface;

/**
 * Manual admin recovery of production jobs.
 *
 * Print jobs:
 * - retry(): POST /order/retry re-runs an existing Printess order under a new job/order id (D12).
 * - resubmit(): for jobs that never got a Printess order (e.g. 406 before an order existed, or an unknown
 *   `submitting` outcome) the job goes back to `pending` with a fresh nonce and the cron submits it.
 *
 * Dropship jobs (Shopware "Restart production"):
 * - resendGroup(): the whole dropship group of the order is sent again via /dropship/produce as a NEW dropship
 *   order (new linked id, new nonces, fresh address, order number `<increment id>-R<n>`). /order/retry is not used
 *   for them: it reuses the linked id and order number, which Printess and dropshippers like Siteflow refuse.
 * - retry() only in mode clear_dropship ("Re-render without dropshipping"; the item becomes a print job).
 * - describeBatch(): what the Approve / Restart page shows.
 *
 * All of them keep the admin in charge of duplicate risk: nothing here is ever called automatically.
 */
class RetryService
{
    public const MODE_DEFAULT        = 'default';
    public const MODE_CLEAR_DROPSHIP = 'clear_dropship';
    public const MODE_RELOAD_PAYMENT = 'reload_payment';
    public const MODES = [self::MODE_DEFAULT, self::MODE_CLEAR_DROPSHIP, self::MODE_RELOAD_PAYMENT];

    /** Printess drops an order's variable data after 30 days; a retry after that would render wrong files. */
    public const RETRY_MAX_AGE_DAYS = 30;

    public const RETRYABLE_STATUSES = [
        PdfJob::STATUS_FAILED,
        PdfJob::STATUS_RENDERED,
        PdfJob::STATUS_PRODUCING,
        PdfJob::STATUS_COMPLETE,
    ];

    public const ERROR_OUTCOME_UNKNOWN = 'outcome_unknown';

    public function __construct(
        private readonly PdfJobRepository $pdfJobRepository,
        private readonly PrintessApiProvider $apiProvider,
        private readonly OrderStageUpdater $stageUpdater,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        private readonly JobSubmitter $jobSubmitter,
        private readonly Config $config,
        private readonly DesignAvailabilityCheck $designCheck
    ) {
    }

    /**
     * Why /order/retry is not possible for this job, or null when it is. Shared with the admin panel so the
     * button state and the server-side check can never disagree.
     */
    public function getRetryBlockReason(PdfJob $job, ?int $now = null): ?string
    {
        if ((string) $job->getData('printess_order_id') === '') {
            return (string) __('No Printess order exists for this item, so it cannot be retried.');
        }
        if (!in_array((string) $job->getData('status'), self::RETRYABLE_STATUSES, true)) {
            return (string) __('Jobs with status "%1" cannot be retried.', (string) $job->getData('status'));
        }

        // No age limit (f22): past the /order/retry window, retry() sends the saved design again instead.
        return null;
    }

    /**
     * Whether /order/retry can no longer be used for this job: Printess drops an order's variable data after
     * RETRY_MAX_AGE_DAYS, so a retry would render wrong files. Then retry() sends the saved design (save token) to
     * Printess again as a new order. An unknown submission date counts as past the window (the safe choice).
     */
    public function isPastRetryWindow(PdfJob $job, ?int $now = null): bool
    {
        // submitted_at is written in UTC; created_at (never later) is a conservative fallback for old rows.
        $reference = (string) ($job->getData('submitted_at') ?: $job->getData('created_at'));
        $submitted = $reference !== '' ? strtotime($reference . ' UTC') : false;
        return $submitted === false || ($now ?? time()) - $submitted > self::RETRY_MAX_AGE_DAYS * 86400;
    }

    /**
     * Why resubmit() is not possible, or null when it is.
     */
    public function getResubmitBlockReason(PdfJob $job): ?string
    {
        if ((string) $job->getData('status') !== PdfJob::STATUS_FAILED) {
            return (string) __('Only failed jobs can be resubmitted.');
        }
        if ((string) $job->getData('printess_order_id') !== '') {
            return (string) __('A Printess order already exists for this item; use Retry instead.');
        }

        return null;
    }

    public function isOutcomeUnknown(PdfJob $job): bool
    {
        return (string) $job->getData('error_code') === self::ERROR_OUTCOME_UNKNOWN;
    }

    /**
     * @throws LocalizedException
     */
    public function retry(int $jobId, string $mode): void
    {
        if (!in_array($mode, self::MODES, true)) {
            throw new LocalizedException(__('Unknown retry mode "%1".', $mode));
        }

        $job = $this->pdfJobRepository->getById($jobId);
        $reason = $this->getRetryBlockReason($job);
        if ($reason !== null) {
            throw new LocalizedException(__($reason));
        }
        if ($mode === self::MODE_CLEAR_DROPSHIP && !$job->isDropship()) {
            throw new LocalizedException(__('Only dropship items can be re-rendered without dropshipping.'));
        }
        if ($job->isDropship() && $mode !== self::MODE_CLEAR_DROPSHIP) {
            throw new LocalizedException(__(
                'Dropship items are not retried one by one. Use "Restart production" to send the dropship group again.'
            ));
        }

        if ($this->isPastRetryWindow($job)) {
            $this->resendFromDesign($job, $mode);
            return;
        }

        $orderId = (int) $job->getData('order_id');
        $storeId = $this->resolveStoreId($job);
        $previousOrderId = (string) $job->getData('printess_order_id');

        try {
            $result = $this->apiProvider->forStore($storeId)->retryOrder(
                $previousOrderId,
                $this->origin($storeId),
                $mode === self::MODE_CLEAR_DROPSHIP,
                $mode === self::MODE_RELOAD_PAYMENT
            );
        } catch (PrintessApiException $e) {
            $this->handleRetryError($job, $mode, $e);
        }

        $previous = [
            'previous_printess_job_id'   => $job->getData('printess_job_id'),
            'previous_printess_order_id' => $previousOrderId,
            'previous_status'            => $job->getData('status'),
            'previous_error_code'        => $job->getData('error_code'),
        ];
        if ($job->getData('dropship_state') !== null) {
            $previous['previous_dropship_state'] = $job->getData('dropship_state');
        }
        if ((string) $job->getData('tracking_id') !== '') {
            $previous['previous_tracking_id'] = $job->getData('tracking_id');
        }

        $job->setData('printess_job_id', $result['jobId']);
        $job->setData('printess_order_id', $result['orderId'] ?? null);
        $job->setData('status', PdfJob::STATUS_PRODUCING);
        $job->setData('submitted_at', gmdate('Y-m-d H:i:s'));
        $job->setData('fail_count', 0);
        $job->setData('error_code', null);
        $job->setData('error_message', null);
        $job->setData('result_json', null);
        // The retried order is a new order at the dropshipper; the old partner state no longer applies.
        $job->setData('dropship_state', null);
        $job->setData('tracking_id', null);
        $job->setData('tracking_url', null);
        $job->setData('tracking_type', null);

        $entry = ['event' => 'retried', 'mode' => $mode] + $previous;
        if ($mode === self::MODE_CLEAR_DROPSHIP) {
            // The merchant fulfils the item: it becomes a print job and leaves the dropship stage derivation.
            $entry['previous_production_type']       = $job->getData('production_type');
            $entry['previous_product_definition_id'] = $job->getData('product_definition_id');
            $job->setData('production_type', PdfJob::TYPE_PRINT);
            $job->setData('product_definition_id', null);
        }
        $entry += [
            'status'            => PdfJob::STATUS_PRODUCING,
            'printess_job_id'   => $result['jobId'],
            'printess_order_id' => $result['orderId'] ?? null,
        ];
        // The nonce is kept on purpose: the retried order inherits the original callback configuration.
        $job->appendHistory($entry);
        $this->pdfJobRepository->save($job);

        $this->logger->info('Printess: production job retried by admin', [
            'job_id'                     => $job->getId(),
            'order_id'                   => $orderId,
            'mode'                       => $mode,
            'previous_printess_order_id' => $previousOrderId,
            'printess_job_id'            => $result['jobId'],
        ]);

        $this->stageUpdater->update($orderId, sprintf(
            'Printess: item %s retried by admin (%s). Previous Printess order %s, new Printess order %s.',
            $this->itemLabel($job),
            $this->modeLabel($mode),
            $previousOrderId,
            (string) ($result['orderId'] ?? 'n/a')
        ));
    }

    /**
     * Retry past the /order/retry window (f22): the item's saved design is checked at Printess and, if it still
     * exists, the job goes back to `pending` with a fresh nonce, so the next cron run sends it via /produce as a
     * NEW Printess order. In mode clear_dropship the dropship item becomes a print job first (as with
     * /order/retry), so it leaves its dropship group.
     *
     * @throws LocalizedException when the design no longer exists at Printess
     */
    private function resendFromDesign(PdfJob $job, string $mode): void
    {
        $this->assertDesignsAvailable([$job], 'retried');

        $entry = [
            'event'                      => 'resent_from_design',
            'mode'                       => $mode,
            'status'                     => PdfJob::STATUS_PENDING,
            'previous_status'            => $job->getData('status'),
            'previous_printess_job_id'   => $job->getData('printess_job_id'),
            'previous_printess_order_id' => $job->getData('printess_order_id'),
            'previous_error_code'        => $job->getData('error_code'),
        ];
        if ($mode === self::MODE_CLEAR_DROPSHIP) {
            $entry['previous_production_type']       = $job->getData('production_type');
            $entry['previous_product_definition_id'] = $job->getData('product_definition_id');
            $job->setData('production_type', PdfJob::TYPE_PRINT);
            $job->setData('product_definition_id', null);
        }

        // A new nonce makes callbacks of the earlier Printess order unmatchable.
        $job->setData('nonce', bin2hex(random_bytes(32)));
        $job->setData('printess_job_id', null);
        $job->setData('printess_order_id', null);
        $job->setData('submitted_at', null);
        $job->setData('error_code', null);
        $job->setData('error_message', null);
        $job->setData('result_json', null);
        $job->setData('dropship_state', null);
        $job->setData('tracking_id', null);
        $job->setData('tracking_url', null);
        $job->setData('tracking_type', null);
        $job->setData('fail_count', 0);
        $job->setData('status', PdfJob::STATUS_PENDING);
        $job->appendHistory($entry);
        $this->pdfJobRepository->save($job);

        $this->logger->info('Printess: production job resent from its saved design by admin', [
            'job_id' => $job->getId(), 'order_id' => $job->getData('order_id'), 'mode' => $mode,
            'previous_printess_order_id' => $entry['previous_printess_order_id'],
        ]);

        $this->stageUpdater->update((int) $job->getData('order_id'), sprintf(
            'Printess: item %s queued again from its saved design (%s): the Printess order %s is older than %d days, '
            . 'so it is sent as a new Printess order by the next cron run.',
            $this->itemLabel($job),
            $this->modeLabel($mode),
            (string) ($entry['previous_printess_order_id'] ?: 'n/a'),
            self::RETRY_MAX_AGE_DAYS
        ));
    }

    /**
     * Puts a failed job without Printess order back into the queue. A job whose last submission outcome is
     * unknown may already exist at Printess, so that case needs the admin's explicit confirmation.
     *
     * @throws LocalizedException
     */
    public function resubmit(int $jobId, bool $confirmedUnknownOutcome = false): void
    {
        $job = $this->pdfJobRepository->getById($jobId);
        if ($job->isDropship()) {
            // A dropship item is never sent on its own: the whole group goes again as a new dropship order.
            $this->resendGroup($jobId);
            return;
        }
        $reason = $this->getResubmitBlockReason($job);
        if ($reason !== null) {
            throw new LocalizedException(__($reason));
        }
        if ($this->isOutcomeUnknown($job) && !$confirmedUnknownOutcome) {
            throw new LocalizedException(__(
                'The previous submission of this item may have reached Printess. Check the Printess order list '
                . 'and confirm the resubmission explicitly.'
            ));
        }
        $this->assertDesignsAvailable([$job], 'resubmitted');

        $previous = [
            'previous_printess_job_id' => $job->getData('printess_job_id'),
            'previous_error_code'      => $job->getData('error_code'),
            'previous_error'           => $job->getData('error_message'),
            'confirmed_unknown'        => $this->isOutcomeUnknown($job),
        ];

        // A new nonce makes callbacks of a possibly existing earlier submission unmatchable.
        $job->setData('nonce', bin2hex(random_bytes(32)));
        $job->setData('printess_job_id', null);
        $job->setData('printess_order_id', null);
        $job->setData('submitted_at', null);
        $job->setData('error_code', null);
        $job->setData('error_message', null);
        $job->setData('result_json', null);
        $job->setData('dropship_state', null);
        $job->setData('fail_count', 0);
        $job->setData('status', PdfJob::STATUS_PENDING);
        $job->appendHistory(['event' => 'resubmitted', 'status' => PdfJob::STATUS_PENDING] + $previous);
        $this->pdfJobRepository->save($job);

        $this->logger->info('Printess: production job resubmitted by admin', [
            'job_id' => $job->getId(), 'order_id' => $job->getData('order_id'),
        ]);

        $this->stageUpdater->update((int) $job->getData('order_id'), sprintf(
            'Printess: item %s queued for resubmission by admin.',
            $this->itemLabel($job)
        ));
    }

    /**
     * Why the dropship group of this job cannot be sent again ("Restart production"), or null when it can.
     * Only a group that went to production before can be restarted: before payment production starts with the
     * payment, while awaiting approval with the approval, and a queued or submitting group is being sent.
     */
    public function getResendBlockReason(PdfJob $job): ?string
    {
        if (!$job->isDropship()) {
            return (string) __('Only dropship items are restarted as a group.');
        }
        $status = (string) $job->getData('status');
        if ($status === PdfJob::STATUS_AWAITING_PAYMENT) {
            return (string) __('Production starts once the order is paid.');
        }
        if ($status === PdfJob::STATUS_AWAITING_APPROVAL) {
            return (string) __('This item is awaiting approval. Approve it to start production.');
        }
        if ($status === PdfJob::STATUS_PENDING) {
            return (string) __('This item is queued and will be sent to Printess by the next cron run.');
        }
        foreach ($this->batchJobs($job) as $member) {
            if ((string) $member->getData('status') === PdfJob::STATUS_SUBMITTING) {
                return (string) __('The dropship group is being sent to Printess right now.');
            }
        }

        return null;
    }

    /**
     * "Restart production" for a dropship item: sends every dropship item of the order (all statuses except
     * awaiting_payment) again as a new dropship order, right away. Tracking (tracking_json) and the Magento shipment
     * link are kept, so the new tracking is added to the existing shipment.
     *
     * Check, reset and send all run under the order's send lock (JobSubmitter::sendDropshipGroupLocked()); the jobs
     * are reloaded inside it, so a concurrent restart / approval or a cron run can never send the group twice or split
     * it. When the group changed since this request read it (it is being sent, or was restarted meanwhile), nothing is
     * done and a LocalizedException explains why.
     *
     * @throws LocalizedException
     */
    public function resendGroup(int $jobId): void
    {
        $job = $this->pdfJobRepository->getById($jobId);
        $reason = $this->getResendBlockReason($job);
        if ($reason !== null) {
            throw new LocalizedException(__($reason));
        }

        $orderId  = (int) $job->getData('order_id');
        // Before anything is reset: a group whose design expired at Printess could never be sent again (D11/f22).
        $this->assertDesignsAvailable($this->batchJobs($job), 'restarted');
        // What this request saw before waiting for the lock; a restart that happened meanwhile changes it.
        $snapshot = $this->groupSnapshot($this->batchJobs($job));

        $this->jobSubmitter->sendDropshipGroupLocked($orderId, function () use ($jobId, $orderId, $snapshot): array {
            $fresh  = $this->pdfJobRepository->getById($jobId);
            $reason = $this->getResendBlockReason($fresh);
            $group  = $this->batchJobs($fresh);
            if ($reason !== null || $this->groupSnapshot($group) !== $snapshot) {
                throw new LocalizedException(__(
                    'The dropship group was not restarted: it is already being sent or was already restarted by '
                    . 'another request%1. Reload the page to see the current state.',
                    $reason !== null ? ' (' . rtrim($reason, '.') . ')' : ''
                ));
            }

            $ids = [];
            foreach ($group as $member) {
                $member->appendHistory([
                    'event'                      => 'resend_requested',
                    'status'                     => PdfJob::STATUS_PENDING,
                    'previous_status'            => $member->getData('status'),
                    'previous_printess_job_id'   => $member->getData('printess_job_id'),
                    'previous_printess_order_id' => $member->getData('printess_order_id'),
                    'previous_shop_order_id'     => $member->getData('dropship_shop_order_id'),
                    'previous_error_code'        => $member->getData('error_code'),
                    'previous_dropship_state'    => $member->getData('dropship_state'),
                ]);
                $member->setData('printess_job_id', null);
                $member->setData('printess_order_id', null);
                $member->setData('result_json', null);
                $member->setData('error_code', null);
                $member->setData('error_message', null);
                $member->setData('dropship_state', null);
                $member->setData('submitted_at', null);
                $member->setData('fail_count', 0);
                // tracking_json / tracking_* / magento_shipment_id are kept on purpose (see above).
                $member->setData('status', PdfJob::STATUS_PENDING);
                $this->pdfJobRepository->save($member);
                $ids[] = (int) $member->getId();
            }

            $this->logger->info('Printess: dropship group restarted by admin', [
                'order_id' => $orderId, 'job_ids' => $ids,
            ]);

            // Sent right after this returns, still inside the lock: new linked id, nonces, address record and
            // `-R<n>` order number; a send failure is recorded on the jobs, never thrown.
            return $ids;
        });
    }

    /**
     * Identifies a send of the group for the "restarted meanwhile" check: job id => send count + linked id (both
     * change with every send). Status is left out on purpose: callbacks / the poller may move a job on while this
     * request waits for the lock, which is no reason to refuse the restart.
     *
     * @param PdfJob[] $group
     * @return array<int, string>
     */
    private function groupSnapshot(array $group): array
    {
        $snapshot = [];
        foreach ($group as $member) {
            $snapshot[(int) $member->getId()] = implode('|', [
                (string) (int) $member->getData('dropship_send_count'),
                (string) $member->getData('linked_order_id'),
            ]);
        }
        ksort($snapshot);
        return $snapshot;
    }

    /**
     * What the Approve / Restart page shows for this job.
     *
     * @return array{dropship: bool, items: list<array{job_id: int, order_item_id: int, name: string, sku: string,
     *     qty: int, thumbnail_url: ?string, status: string, outcome_unknown: bool}>, next_shop_order_id: ?string,
     *     reproduction_mode: string, order_id: int, order_increment_id: string}
     * @throws LocalizedException
     */
    public function describeBatch(int $jobId): array
    {
        $job     = $this->pdfJobRepository->getById($jobId);
        $orderId = (int) $job->getData('order_id');
        $order   = null;
        try {
            $order = $this->orderRepository->get($orderId);
        } catch (\Throwable) {
            $order = null;
        }

        $batch = $this->batchJobs($job);
        $items = [];
        // The next order number counts every dropship send of the order (JobSubmitter does the same).
        $sendCounts = [];
        foreach ($this->pdfJobRepository->getByOrderId($orderId) as $other) {
            if ($other->isDropship()) {
                $sendCounts[] = $other->getData('dropship_send_count');
            }
        }
        foreach ($batch as $member) {
            $itemId = (int) $member->getData('order_item_id');
            $item   = $order instanceof Order ? $order->getItemById($itemId) : null;
            $item   = $item instanceof OrderItem ? $item : null;
            $sendCounts[] = $member->getData('dropship_send_count');
            $items[] = [
                'job_id'          => (int) $member->getId(),
                'order_item_id'   => $itemId,
                'name'            => $item ? (string) $item->getName() : (string) __('Order item #%1', $itemId),
                'sku'             => $item ? (string) $item->getSku() : '',
                'qty'             => $item ? max(1, (int) $item->getQtyOrdered()) : max(1, (int) $member->getData('copies')),
                'thumbnail_url'   => $item ? $this->thumbnailUrl($item) : null,
                'status'          => (string) $member->getData('status'),
                'outcome_unknown' => $this->isOutcomeUnknown($member),
            ];
        }

        $incrementId = $order ? (string) $order->getIncrementId() : '';
        $storeId     = $order ? (int) $order->getStoreId() : null;

        return [
            'dropship'           => $job->isDropship(),
            'items'              => $items,
            'next_shop_order_id' => $job->isDropship() && $incrementId !== ''
                ? DropshipShopOrderId::build($incrementId, DropshipShopOrderId::nextSendNumber($sendCounts))
                : null,
            'reproduction_mode'  => $this->config->getDropshipReproductionMode($storeId),
            'order_id'           => $orderId,
            'order_increment_id' => $incrementId,
        ];
    }

    /**
     * The jobs approved or restarted together with $job: just $job for a print job; for a dropship job awaiting
     * approval the order's dropship jobs awaiting approval; otherwise every dropship job of the order except those
     * still awaiting payment (a Magento order has one shipping address, so it has one dropship group).
     *
     * @return PdfJob[]
     */
    private function batchJobs(PdfJob $job): array
    {
        if (!$job->isDropship()) {
            return [$job];
        }
        $awaitingApproval = (string) $job->getData('status') === PdfJob::STATUS_AWAITING_APPROVAL;

        $batch = array_values(array_filter(
            $this->pdfJobRepository->getByOrderId((int) $job->getData('order_id')),
            static fn (PdfJob $j): bool => $j->isDropship() && ($awaitingApproval
                ? (string) $j->getData('status') === PdfJob::STATUS_AWAITING_APPROVAL
                : (string) $j->getData('status') !== PdfJob::STATUS_AWAITING_PAYMENT)
        ));

        return $batch !== [] ? $batch : [$job];
    }

    /** The personalisation thumbnail stored with the order item (https only), or null. */
    private function thumbnailUrl(OrderItem $item): ?string
    {
        $options = $item->getProductOptions();
        $url = is_array($options) ? ($options['additional_options']['printess_thumbnail_url']['value'] ?? null) : null;
        if (!is_string($url)) {
            return null;
        }
        $url = trim($url);
        return str_starts_with(strtolower($url), 'https://') ? $url : null;
    }

    /**
     * @throws LocalizedException always
     */
    private function handleRetryError(PdfJob $job, string $mode, PrintessApiException $e): never
    {
        $message = $e->getPrintessMessage() ?? $e->getMessage();

        if (!$e->isPermanent()) {
            // Printess may have created the retry order anyway; leave a trace so nobody retries blindly.
            try {
                $job->appendHistory([
                    'event' => 'retry_outcome_unknown',
                    'mode'  => $mode,
                    'error' => $e->getMessage(),
                ]);
                $this->pdfJobRepository->save($job);
            } catch (\Throwable $inner) {
                $this->logger->error('Printess: could not record retry failure', [
                    'job_id' => $job->getId(), 'error' => $inner->getMessage(),
                ]);
            }
            $this->logger->error('Printess: retry outcome unknown', ['job_id' => $job->getId(), 'error' => $e->getMessage()]);

            throw new LocalizedException(__(
                'The retry could not be confirmed by Printess (%1). It may or may not have been created; check the '
                . 'Printess order list before retrying again.',
                $message
            ), $e);
        }

        $this->logger->warning('Printess: retry rejected', ['job_id' => $job->getId(), 'error' => $e->getMessage()]);
        throw new LocalizedException(__('Printess rejected the retry: %1', $message), $e);
    }

    private function resolveStoreId(PdfJob $job): ?int
    {
        $storeId = $job->getData('store_id');
        if ($storeId !== null && $storeId !== '') {
            return (int) $storeId;
        }
        try {
            return (int) $this->orderRepository->get((int) $job->getData('order_id'))->getStoreId();
        } catch (\Throwable) {
            return null;
        }
    }

    private function origin(?int $storeId): string
    {
        try {
            return 'Magento retry ' . $this->storeManager->getStore($storeId)->getBaseUrl();
        } catch (\Throwable) {
            return 'Magento retry';
        }
    }

    private function modeLabel(string $mode): string
    {
        return match ($mode) {
            self::MODE_CLEAR_DROPSHIP => 're-render without dropshipping',
            self::MODE_RELOAD_PAYMENT => 'with payment reload',
            default                   => 'production retry',
        };
    }

    /** The item's SKU for order comments, or "#<item id>" when it can't be loaded. Never throws. */
    /**
     * @param PdfJob[] $jobs
     * @throws LocalizedException when a design of these jobs no longer exists at Printess
     */
    private function assertDesignsAvailable(array $jobs, string $action): void
    {
        if ($jobs === []) {
            return;
        }
        $storeId = $jobs[0]->getData('store_id');
        $storeId = $storeId !== null && $storeId !== '' ? (int) $storeId : null;
        $designs = [];
        foreach ($jobs as $job) {
            $designs[] = ['label' => $this->itemLabel($job), 'token' => (string) $job->getData('save_token')];
        }
        $this->designCheck->assertAvailable($storeId, $designs, $action);
    }

    private function itemLabel(PdfJob $job): string
    {
        $itemId = (int) $job->getData('order_item_id');
        try {
            $sku = (string) $this->orderRepository->get((int) $job->getData('order_id'))->getItemById($itemId)?->getSku();
            if ($sku !== '') {
                return $sku;
            }
        } catch (\Throwable) {
            // fall through to the id
        }
        return '#' . $itemId;
    }
}
