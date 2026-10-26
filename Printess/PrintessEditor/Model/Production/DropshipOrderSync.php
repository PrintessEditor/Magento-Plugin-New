<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Production;

use Magento\Sales\Api\OrderRepositoryInterface;
use Printess\PrintessEditor\Model\PdfJob;
use Printess\PrintessEditor\Model\PdfJobRepository;
use Printess\PrintessEditor\Model\PrintessApi;
use Printess\PrintessEditor\Model\PrintessApiProvider;
use Printess\PrintessEditor\Model\ResourceModel\PdfJob\Collection;
use Printess\PrintessEditor\Model\ResourceModel\PdfJob\CollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Dropship poller: reads POST /dropship/orders/list and applies partner states to open dropship jobs.
 *
 * Primary tracking source for callback_mode = poll, and a safety net for callback-mode jobs that have not heard
 * back for 24 h. Behaves like the tracking callback (Controller/Dropship/Callback): progress states are recorded,
 * shipped goes through the idempotent DropshipShipmentService, 98/99 fail the job without halting the order (D9).
 *
 * Printess entries are matched by `orderId` (the Printess order id stored on the job) or by
 * `linked/<linkedOrderLineItemsId>` for combined orders, which then applies to every open job of that linked order.
 * The partner's `externalOrderId` is deliberately ignored.
 *
 * Resends (RetryService::resendGroup): every send gets a NEW linked id and the job's Printess order id is cleared,
 * so only the job's CURRENT ids are indexed and entries of an earlier send can no longer match; entries created before
 * the job's (latest) submitted_at are ignored as well (predatesSubmission), so an old `linked/<id>` entry can neither
 * re-fail nor re-ship a resent item.
 */
class DropshipOrderSync
{
    private const PAGE_SIZE          = 500;
    private const MAX_PAGES          = 20;
    private const MAX_JOBS_PER_RUN   = 1000;
    private const CALLBACK_WAIT_SECS = 86400;
    private const WINDOW_MARGIN_SECS = 86400;
    /** Fallback window when no job carries a usable timestamp. */
    private const DEFAULT_WINDOW_SECS = 30 * 86400;

    private const OPEN_STATUSES = [PdfJob::STATUS_PRODUCING, PdfJob::STATUS_RENDERED];

    /** Resolution order when several list entries match the same job. */
    private const ACTION_RANK = [
        DropshipOrderStateMapper::ACTION_SHIPPED  => 3,
        DropshipOrderStateMapper::ACTION_FAILED   => 2,
        DropshipOrderStateMapper::ACTION_PROGRESS => 1,
        DropshipOrderStateMapper::ACTION_NONE     => 1,
    ];

    private const LINKED_PREFIX = 'linked/';

    /**
     * Clock tolerance when discarding list entries created before the job's (latest) submission. A retried item
     * gets a new Printess order; the stale combined `linked/<id>` entry from the earlier attempt must not re-fail it.
     */
    private const SUBMIT_TOLERANCE_SECS = 120;

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly PdfJobRepository $pdfJobRepository,
        private readonly PrintessApiProvider $apiProvider,
        private readonly DropshipOrderStateMapper $mapper,
        private readonly DropshipShipmentService $shipmentService,
        private readonly OrderStageUpdater $orderStageUpdater,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Cron entry: all open poll-mode jobs plus callback-mode jobs without an answer for 24 h.
     *
     * @return int number of jobs changed
     */
    public function syncOpenJobs(): int
    {
        $cutoff     = gmdate('Y-m-d H:i:s', time() - self::CALLBACK_WAIT_SECS);
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('production_type', PdfJob::TYPE_DROPSHIP);
        $collection->addFieldToFilter('status', ['in' => self::OPEN_STATUSES]);
        // OR groups (each call is AND-ed with the others).
        $collection->addFieldToFilter(
            ['printess_order_id', 'linked_order_id'],
            [['notnull' => true], ['notnull' => true]]
        );
        $collection->addFieldToFilter(
            ['callback_mode', 'submitted_at', 'submitted_at'],
            [['neq' => PdfJob::CALLBACK_MODE_CALLBACK], ['lt' => $cutoff], ['null' => true]]
        );
        $collection->setOrder('job_id', Collection::SORT_ORDER_ASC);
        $collection->setPageSize(self::MAX_JOBS_PER_RUN);
        $collection->setCurPage(1);

        $jobs = array_values($collection->getItems());
        if (count($jobs) >= self::MAX_JOBS_PER_RUN) {
            $this->logger->warning(sprintf(
                'Printess dropship sync: more than %d open dropship jobs; the rest is synced on a later run.',
                self::MAX_JOBS_PER_RUN
            ));
        }

        return $this->syncJobs($jobs);
    }

    /**
     * Admin "Check status": all open dropship jobs of one order, regardless of callback mode or age.
     *
     * @return int number of jobs changed
     */
    public function syncOrder(int $orderId): int
    {
        $jobs = array_values(array_filter(
            $this->pdfJobRepository->getByOrderId($orderId),
            fn (PdfJob $job): bool => $this->isOpen($job)
        ));

        return $this->syncJobs($jobs);
    }

    /**
     * @param PdfJob[] $jobs
     */
    private function syncJobs(array $jobs): int
    {
        if ($jobs === []) {
            return 0;
        }

        /** @var array<int, PdfJob[]> $byStore */
        $byStore = [];
        foreach ($jobs as $job) {
            $storeId = $this->storeIdOf($job);
            if ($storeId !== null) {
                $byStore[$storeId][] = $job;
            }
        }

        $changed = 0;
        foreach ($byStore as $storeId => $storeJobs) {
            if (!$this->apiProvider->hasServiceToken($storeId)) {
                $this->logger->warning(sprintf(
                    'Printess dropship sync: no service token for store %d; %d job(s) skipped.',
                    $storeId,
                    count($storeJobs)
                ));
                continue;
            }
            try {
                $changed += $this->syncStore($this->apiProvider->forStore($storeId), $storeId, $storeJobs);
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf('Printess dropship sync failed for store %d: %s', $storeId, $e->getMessage()),
                    ['exception' => $e]
                );
            }
        }

        return $changed;
    }

    /**
     * @param PdfJob[] $jobs
     */
    private function syncStore(PrintessApi $api, int $storeId, array $jobs): int
    {
        $orders = $this->fetchOrders($api, $storeId, $this->windowStart($jobs));

        // Index open jobs by the keys Printess reports in `orderId`.
        /** @var array<string, PdfJob[]> $index */
        $index = [];
        foreach ($jobs as $job) {
            $printessOrderId = trim((string) $job->getData('printess_order_id'));
            if ($printessOrderId !== '') {
                $index[$printessOrderId][] = $job;
            }
            $linkedId = trim((string) $job->getData('linked_order_id'));
            if ($linkedId !== '') {
                $index[self::LINKED_PREFIX . $linkedId][] = $job;
            }
        }

        // Several entries can match one job (its own order and the combined linked order): keep the strongest.
        /** @var array<int, array{job: PdfJob, mapped: array, rank: int, created: int, type: ?string}> $best */
        $best = [];
        foreach ($orders as $order) {
            if (!is_array($order) || !is_scalar($order['orderId'] ?? null)) {
                continue;
            }
            $key = trim((string) $order['orderId']);
            if ($key === '' || !isset($index[$key])) {
                continue;
            }

            $mapped = $this->mapper->map($order);
            if ($mapped['state'] === null) {
                continue;
            }
            $rank    = self::ACTION_RANK[$mapped['action']] ?? 0;
            $createdTs = $this->toTimestamp($order['createdOn'] ?? null);
            $created   = $createdTs ?? 0;
            $type    = is_scalar($order['dropshipperType'] ?? null) && !is_bool($order['dropshipperType'])
                ? trim((string) $order['dropshipperType'])
                : '';

            foreach ($index[$key] as $job) {
                if ($this->predatesSubmission($createdTs, $job)) {
                    continue; // entry belongs to an earlier submission attempt of this item
                }
                $jobId   = (int) $job->getId();
                $current = $best[$jobId] ?? null;
                if ($current === null
                    || $rank > $current['rank']
                    || ($rank === $current['rank'] && $created >= $current['created'])
                ) {
                    $best[$jobId] = [
                        'job'     => $job,
                        'mapped'  => $mapped,
                        'rank'    => $rank,
                        'created' => $created,
                        'type'    => $type !== '' ? $type : null,
                    ];
                }
            }
        }

        $changed = 0;
        foreach ($best as $entry) {
            try {
                if ($this->apply($entry['job'], $entry['mapped'], $entry['type'])) {
                    $changed++;
                }
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf(
                        'Printess dropship sync: could not apply partner state to job %d: %s',
                        (int) $entry['job']->getId(),
                        $e->getMessage()
                    ),
                    ['exception' => $e]
                );
            }
        }

        return $changed;
    }

    /**
     * @return array<int, mixed> raw list entries
     */
    private function fetchOrders(PrintessApi $api, int $storeId, int $startTs): array
    {
        $filter = [
            'startDate' => gmdate('Y-m-d\TH:i:s\Z', $startTs),
            'endDate'   => gmdate('Y-m-d\TH:i:s\Z'),
            'take'      => self::PAGE_SIZE,
        ];

        $orders = [];
        $skip   = 0;
        $total  = 0;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $api->listDropshipOrders($filter + ['skip' => $skip]);
            $batch    = is_array($response['orders'] ?? null) ? array_values($response['orders']) : [];
            $total    = (int) ($response['count'] ?? 0);

            array_push($orders, ...$batch);
            $skip += count($batch);

            if ($batch === [] || $skip >= $total) {
                return $orders;
            }
        }

        $this->logger->warning(sprintf(
            'Printess dropship sync: page limit (%d x %d) reached for store %d; read %d of %d orders.',
            self::MAX_PAGES,
            self::PAGE_SIZE,
            $storeId,
            $skip,
            $total
        ));

        return $orders;
    }

    /**
     * Oldest submission in the group minus a day, so orders created slightly before submitted_at was written
     * (or reported with a clock skew) are still in the window.
     *
     * @param PdfJob[] $jobs
     */
    private function windowStart(array $jobs): int
    {
        $oldest = null;
        foreach ($jobs as $job) {
            $ts = $this->toTimestamp($job->getData('submitted_at')) ?? $this->toTimestamp($job->getData('created_at'));
            if ($ts !== null && ($oldest === null || $ts < $oldest)) {
                $oldest = $ts;
            }
        }

        return ($oldest ?? time() - self::DEFAULT_WINDOW_SECS) - self::WINDOW_MARGIN_SECS;
    }

    /**
     * @return bool whether the job changed
     */
    private function apply(PdfJob $job, array $mapped, ?string $dropshipperType): bool
    {
        if (!$this->isOpen($job)) {
            return false; // e.g. shipped by the tracking callback since we loaded it
        }

        $state   = $mapped['state'];
        $orderId = (int) $job->getData('order_id');

        switch ($mapped['action']) {
            case DropshipOrderStateMapper::ACTION_SHIPPED:
                $job->setData('dropship_state', $state);
                // Saves the job (incl. dropship_state), creates the shipment and updates the order stage.
                $this->shipmentService->markShipped(
                    $job,
                    (string) $mapped['tracking_id'],
                    $mapped['tracking_url'],
                    $dropshipperType
                );
                return true;

            case DropshipOrderStateMapper::ACTION_FAILED:
                $message = (string) $mapped['error'];
                $job->setData('dropship_state', $state);
                $job->setData('status', PdfJob::STATUS_FAILED);
                $job->setData('error_code', 'partner_' . $state);
                $job->setData('error_message', $message);
                $job->appendHistory([
                    'event'   => 'dropship_poll',
                    'status'  => PdfJob::STATUS_FAILED,
                    'state'   => $state,
                    'message' => $message,
                ]);
                $this->pdfJobRepository->save($job);

                $this->orderStageUpdater->update(
                    $orderId,
                    sprintf(
                        'Printess dropship: item %s %s by dropshipper (state %d): %s',
                        $this->itemLabel($job, $orderId),
                        $state === DropshipOrderStateMapper::STATE_CANCELLED ? 'cancelled' : 'error',
                        $state,
                        self::readableError((string) $message)
                    )
                );
                return true;

            default:
                // progress, or an unknown state that is only recorded
                $current = $job->getData('dropship_state');
                if ($current !== null && $current !== '' && (int) $current === $state) {
                    return false;
                }
                $job->setData('dropship_state', $state);
                $job->appendHistory(['event' => 'dropship_poll', 'state' => $state]);
                $this->pdfJobRepository->save($job);
                $this->orderStageUpdater->update($orderId);
                return true;
        }
    }

    private function isOpen(PdfJob $job): bool
    {
        return $job->isDropship()
            && in_array((string) $job->getData('status'), self::OPEN_STATUSES, true)
            && (trim((string) $job->getData('printess_order_id')) !== ''
                || trim((string) $job->getData('linked_order_id')) !== '');
    }

    /** Rows created before store_id existed fall back to the order's store. */
    private function storeIdOf(PdfJob $job): ?int
    {
        $storeId = $job->getData('store_id');
        if ($storeId !== null && $storeId !== '') {
            return (int) $storeId;
        }

        try {
            return (int) $this->orderRepository->get((int) $job->getData('order_id'))->getStoreId();
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                'Printess dropship sync: could not resolve store of job %d: %s',
                (int) $job->getId(),
                $e->getMessage()
            ));
            return null;
        }
    }

    /**
     * True when the list entry was created clearly before the job was (re)submitted. Unknown or unparsable
     * dates never discard an entry.
     */
    private function predatesSubmission(?int $createdTs, PdfJob $job): bool
    {
        if ($createdTs === null) {
            return false;
        }
        $submittedTs = $this->toTimestamp($job->getData('submitted_at'));
        return $submittedTs !== null && $createdTs < $submittedTs - self::SUBMIT_TOLERANCE_SECS;
    }

    /** DB timestamps are written in UTC (gmdate); partner dates without an offset are read as UTC too. */
    private function toTimestamp(mixed $value): ?int
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable(trim($value), new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }

    /** The item's SKU for order comments, or "#<item id>" when it can't be loaded. Never throws. */
    private function itemLabel(PdfJob $job, int $orderId): string
    {
        $itemId = (int) $job->getData('order_item_id');
        try {
            $sku = (string) $this->orderRepository->get($orderId)->getItemById($itemId)?->getSku();
            if ($sku !== '') {
                return $sku;
            }
        } catch (\Throwable) {
            // fall through to the id
        }
        return '#' . $itemId;
    }

    /**
     * Partner errors arrive with the backend's .NET stack trace appended (" at Printess.….SendOrder(…) in …:line 233
     * at …"). The merchant needs the message, not the trace; the full text stays in the job's error_message.
     */
    public static function readableError(string $message, int $maxLength = 1500): string
    {
        $trimmed = preg_replace('/\s+at\s+[A-Za-z_][\w.`<>]*\.[\w`<>]+\(.*$/s', '', $message) ?? $message;
        $trimmed = trim($trimmed);
        if ($trimmed === '') {
            $trimmed = trim($message);
        }
        return mb_strlen($trimmed) > $maxLength ? mb_substr($trimmed, 0, $maxLength - 1) . '…' : $trimmed;
    }
}
