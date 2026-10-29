<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Production;

use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\Data\ShipmentCommentCreationInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentItemCreationInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentTrackCreationInterfaceFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Api\ShipOrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\ShipmentCommentSender;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\TrackFactory;
use Printess\PrintessEditor\Helper\Config;
use Printess\PrintessEditor\Model\PdfJob;
use Printess\PrintessEditor\Model\PdfJobRepository;
use Psr\Log\LoggerInterface;

/**
 * Turns "the dropshipper shipped these items" into native Magento shipments with tracking.
 *
 * Shared by the type-3 tracking callback (several items per call), the legacy type-1 callback and the dropship
 * poller (one item each, via markShipped), so it must be idempotent:
 * - every job keeps a tracking list (tracking_json); a (trackingId, trackingUrl) already recorded for the job's
 *   CURRENT send is ignored, because Printess retries callbacks and the poller may report what a callback already
 *   did. The same (or an empty) tracking reported after a restart belongs to a new send and is processed: the job
 *   becomes `shipped` again even when there is nothing new to put on the Magento shipment;
 * - all work for one order is serialised by a per-order lock and done on freshly loaded jobs.
 *
 * Items with quantity left to ship get ONE new shipment per call (one track per distinct tracking id). Items that
 * are already fully shipped (a re-send) get the track on their existing shipment unless that tracking number is
 * already on it, and a shipment-comment email (when "Send shipment email" is on) only for tracking numbers that were
 * actually added; the job is set to `shipped` either way. A failed shipment never loses the tracking info: the job is still updated and
 * the merchant is told to ship manually.
 */
class DropshipShipmentService
{
    private const CARRIER_CODE  = 'custom';
    private const DEFAULT_TITLE = 'Dropshipper';
    private const LOCK_PREFIX   = 'printess_dropship_ship_order_';
    private const LOCK_TIMEOUT  = 15;

    /** Order states in which a new shipment may be created. */
    private const SHIP_STATES = [Order::STATE_NEW, Order::STATE_PROCESSING];

    /** Order states in which a track may be added to an existing shipment (a re-send usually hits `complete`). */
    private const TRACK_STATES = [Order::STATE_NEW, Order::STATE_PROCESSING, Order::STATE_COMPLETE];

    /** Job statuses before the item was handed to Printess: tracking is recorded, nothing else changes. */
    private const PRE_SEND_STATUSES = [
        PdfJob::STATUS_AWAITING_PAYMENT,
        PdfJob::STATUS_AWAITING_APPROVAL,
        'released',
        PdfJob::STATUS_PENDING,
    ];

    /** Job statuses in which a "shipped" report is expected. Others are processed but logged. */
    private const SHIPPABLE_STATUSES = [
        PdfJob::STATUS_SUBMITTING,
        PdfJob::STATUS_PRODUCING,
        PdfJob::STATUS_RENDERED,
        PdfJob::STATUS_SHIPPED,
    ];

    private const MODE_SHIP   = 'ship';
    private const MODE_TRACK  = 'track';
    private const MODE_RECORD = 'record';

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ShipOrderInterface $shipOrder,
        private readonly ShipmentItemCreationInterfaceFactory $itemCreationFactory,
        private readonly ShipmentTrackCreationInterfaceFactory $trackCreationFactory,
        private readonly ShipmentCommentCreationInterfaceFactory $commentCreationFactory,
        private readonly ShipmentRepositoryInterface $shipmentRepository,
        private readonly TrackFactory $trackFactory,
        private readonly PdfJobRepository $pdfJobRepository,
        private readonly OrderStageUpdater $orderStageUpdater,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly LockManagerInterface $lockManager,
        private readonly ShipmentCommentSender $shipmentCommentSender,
        private readonly DropshipCallbackParser $trackingParser
    ) {
    }

    /**
     * Single item (poller, legacy type-1 callback). A dropship_state set on $job by the caller is kept.
     */
    public function markShipped(PdfJob $job, string $trackingId, ?string $trackingUrl, ?string $trackingType): void
    {
        $this->markShippedBatch((int) $job->getData('order_id'), [[
            'job'           => $job,
            'tracking_id'   => $trackingId,
            'tracking_url'  => $trackingUrl,
            'tracking_type' => $trackingType,
        ]]);
    }

    /**
     * @param array $items list of ['job' => PdfJob, 'tracking_id' => string, 'tracking_url' => ?string,
     *                     'tracking_type' => ?string (optional, used as the track title)]
     * @throws \RuntimeException when the order is locked by another request (the caller should retry later)
     */
    public function markShippedBatch(int $orderId, array $items): void
    {
        if ($orderId <= 0 || $items === []) {
            return;
        }

        // Callback, callback retry and poller can report the same shipment at the same moment. Serialise per
        // order and re-read the jobs inside the lock, so the second caller sees the first caller's tracking list
        // and shipment id and never ships an item twice.
        $lockName = self::LOCK_PREFIX . $orderId;
        if (!$this->lockManager->lock($lockName, self::LOCK_TIMEOUT)) {
            $this->logger->warning(sprintf('Printess dropship: could not lock order %d for shipping; will retry on next update.', $orderId));
            throw new \RuntimeException('Printess dropship: shipments for this order are being processed by another request.');
        }
        try {
            $this->processLocked($orderId, $items);
        } finally {
            $this->lockManager->unlock($lockName);
        }
    }

    private function processLocked(int $orderId, array $items): void
    {
        /** @var array<int, PdfJob> $jobs */
        $jobs = [];
        /** @var array<int, list<array{id: string, url: string, type: ?string}>> $reports */
        $reports = [];

        foreach ($items as $item) {
            $given = is_array($item) ? ($item['job'] ?? null) : null;
            if (!$given instanceof PdfJob || !(int) $given->getId()) {
                continue;
            }
            $jobId = (int) $given->getId();
            if ((int) $given->getData('order_id') !== $orderId) {
                $this->logger->warning(sprintf('Printess dropship: job %d does not belong to order %d; ignored.', $jobId, $orderId));
                continue;
            }
            if (!isset($jobs[$jobId])) {
                try {
                    $fresh = $this->pdfJobRepository->getById($jobId);
                } catch (\Throwable $e) {
                    $this->logger->error(sprintf('Printess dropship: job %d not loadable: %s', $jobId, $e->getMessage()));
                    continue;
                }
                $state = $given->getData('dropship_state');
                if ($state !== null && (string) $state !== (string) $fresh->getData('dropship_state')) {
                    $fresh->setData('dropship_state', $state);
                }
                $jobs[$jobId] = $fresh;
            }
            $reports[$jobId][] = [
                'id'   => $this->str($item['tracking_id'] ?? null),
                'url'  => $this->str($item['tracking_url'] ?? null),
                'type' => $this->str($item['tracking_type'] ?? null) ?: null,
            ];
        }
        if ($jobs === []) {
            return;
        }

        $order = null;
        try {
            $loaded = $this->orderRepository->get($orderId);
            $order  = $loaded instanceof Order ? $loaded : null;
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Printess dropship: order %d not loadable: %s', $orderId, $e->getMessage()));
        }
        $orderState = $order !== null ? (string) $order->getState() : '';
        $storeId    = $order !== null ? ((int) $order->getStoreId() ?: null) : null;
        $now        = gmdate(DATE_ATOM);

        // ---- 1. merge tracking, drop repeats, decide what Magento should do per job ---------------------------------
        $plans = [];
        foreach ($jobs as $jobId => $job) {
            $send = (int) $job->getData('dropship_send_count');
            $list = $this->existingTracking($job, $send);
            $new  = [];
            foreach ($reports[$jobId] as $report) {
                $merged = $this->trackingParser->mergeTracking(
                    $list,
                    ['trackingId' => $report['id'], 'trackingUrl' => $report['url'], 'shippedAt' => $now],
                    $send
                );
                $list = $merged['list'];
                if ($merged['added']) {
                    $new[] = $report;
                }
            }

            if ($new === []) {
                // Exact repeat within the current send (callback retry, or poller after callback): nothing new.
                if ($job->hasDataChanges()) {
                    $this->pdfJobRepository->save($job); // e.g. a new dropship_state from the caller
                }
                continue;
            }

            $status = (string) $job->getData('status');
            $orderItemId = (int) $job->getData('order_item_id');
            $orderItem = $order?->getItemById($orderItemId);
            $orderItem = $orderItem instanceof OrderItemInterface ? $orderItem : null;

            $plan = [
                'job'      => $job,
                'list'     => $list,
                'new'      => $new,
                'item_id'  => $orderItemId,
                'sku'      => $orderItem !== null ? (string) $orderItem->getSku() : ('item ' . $orderItemId),
                'pre_send' => in_array($status, self::PRE_SEND_STATUSES, true),
                'warning'  => null,
                'failure'  => null,
                'note'     => null,
                'mode'     => self::MODE_RECORD,
                'qty'      => 0.0,
            ];

            if (!in_array($status, self::SHIPPABLE_STATUSES, true)) {
                $plan['warning'] = sprintf('job status is %s', $status !== '' ? $status : 'empty');
                $this->logger->warning(sprintf(
                    'Printess dropship: shipment reported for job %d (order %d, item %d) in status "%s"%s.',
                    $jobId,
                    $orderId,
                    $orderItemId,
                    $status,
                    $plan['pre_send'] ? '; tracking recorded, job left unchanged' : ''
                ));
            }

            if ($plan['pre_send']) {
                $plan['note'] = sprintf('the item is "%s" in Magento, so no shipment was created; please check', $status);
            } elseif ($order === null) {
                $plan['failure'] = sprintf('order %d not loadable', $orderId);
            } elseif ($orderItem === null) {
                $plan['failure'] = sprintf('order item %d not found', $orderItemId);
            } else {
                $qty = $this->qtyToShip($orderItem);
                $shipmentId = (int) $job->getData('magento_shipment_id');
                if ($qty > 0.0) {
                    if (in_array($orderState, self::SHIP_STATES, true)) {
                        $plan['mode'] = self::MODE_SHIP;
                        $plan['qty']  = $qty;
                    } else {
                        $plan['note'] = sprintf('order is %s, so no shipment was created', $orderState);
                    }
                } elseif ($shipmentId > 0) {
                    if (in_array($orderState, self::TRACK_STATES, true)) {
                        $plan['mode'] = self::MODE_TRACK;
                    } else {
                        $plan['note'] = sprintf('order is %s, so shipment #%d was not changed', $orderState, $shipmentId);
                    }
                }
                // qty 0 without our shipment: shipped in Magento by someone else — only the tracking is recorded.
            }

            $plans[$jobId] = $plan;
        }
        if ($plans === []) {
            return;
        }

        // ---- 2. one new shipment for everything that still has quantity to ship -----------------------------------
        $toShip = array_filter($plans, static fn (array $p): bool => $p['mode'] === self::MODE_SHIP);
        if ($toShip !== []) {
            try {
                $shipmentId = $this->createShipment(
                    $orderId,
                    $toShip,
                    $this->config->isSendShipmentEmail($storeId)
                );
                foreach (array_keys($toShip) as $jobId) {
                    $plans[$jobId]['shipment_id'] = $shipmentId;
                }
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf('Printess dropship: could not create shipment for order %d: %s', $orderId, $e->getMessage()),
                    ['exception' => $e]
                );
                foreach (array_keys($toShip) as $jobId) {
                    $plans[$jobId]['failure'] = $e->getMessage();
                }
            }
        }

        // ---- 3. re-shipped items: new track on their existing shipment + update email -----------------------------
        $byShipment = [];
        foreach ($plans as $jobId => $plan) {
            if ($plan['mode'] === self::MODE_TRACK) {
                $byShipment[(int) $plan['job']->getData('magento_shipment_id')][] = $jobId;
            }
        }
        foreach ($byShipment as $shipmentId => $jobIds) {
            $reportsHere = [];
            foreach ($jobIds as $jobId) {
                foreach ($plans[$jobId]['new'] as $report) {
                    $reportsHere[] = $report;
                }
            }
            $result = $this->addTracksToShipment($shipmentId, $reportsHere, $storeId);
            foreach ($jobIds as $jobId) {
                $plans[$jobId]['shipment_id'] = $shipmentId;
                $plans[$jobId]['failure']     = $result['error'];
                $plans[$jobId]['tracks_added'] = array_values(array_intersect(
                    $result['added'],
                    array_column($plans[$jobId]['new'], 'id')
                ));
            }
        }

        // ---- 4. jobs + order stage --------------------------------------------------------------------------------
        $comments = [];
        foreach ($plans as $plan) {
            $comments[] = $this->updateJob($plan, $now);
        }
        $this->orderStageUpdater->update($orderId, implode("\n", $comments));
    }

    /**
     * Stored tracking list. Jobs shipped before tracking_json existed get their single tracking column value as
     * the first entry, so a repeated legacy report is still recognised as a repeat.
     */
    private function existingTracking(PdfJob $job, int $send): array
    {
        $list = $this->trackingParser->decodeTracking($job->getData('tracking_json'));
        if ($list !== []) {
            return $list;
        }
        $isShipped  = (string) $job->getData('status') === PdfJob::STATUS_SHIPPED;
        $trackingId = $this->str($job->getData('tracking_id'));
        if (!$isShipped && $trackingId === '') {
            return [];
        }
        $shippedAt = $this->str($job->getData('shipped_at'));
        try {
            $shippedAt = $shippedAt !== ''
                ? (new \DateTimeImmutable($shippedAt, new \DateTimeZone('UTC')))->format(DATE_ATOM)
                : '';
        } catch (\Throwable) {
            $shippedAt = '';
        }
        return [[
            'trackingId'  => $trackingId,
            'trackingUrl' => $this->str($job->getData('tracking_url')),
            'shippedAt'   => $shippedAt,
            'send'        => $isShipped ? $send : max(0, $send - 1),
        ]];
    }

    /**
     * @return string the order comment line for this job
     */
    private function updateJob(array $plan, string $now): string
    {
        /** @var PdfJob $job */
        $job        = $plan['job'];
        $new        = $plan['new'];
        $latest     = $new[count($new) - 1];
        $shipmentId = (int) ($plan['shipment_id'] ?? 0);
        $failure    = $plan['failure'];

        $job->setData('tracking_json', json_encode(
            $plan['list'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        ));
        foreach ($new as $report) {
            if ($report['id'] !== '') {
                $job->setData('tracking_id', $report['id']);
            }
            if ($report['url'] !== '') {
                $job->setData('tracking_url', $report['url']);
            }
            if ($report['type'] !== null) {
                $job->setData('tracking_type', $report['type']);
            }
        }

        if (!$plan['pre_send']) {
            $job->setData('status', PdfJob::STATUS_SHIPPED);
            // A shipped job carries no error any more (e.g. an earlier partner_99 or outcome_unknown);
            // a shipment problem is kept in the history and the order comment instead.
            $job->setData('error_code', null);
            $job->setData('error_message', null);
            $job->setData('shipped_at', gmdate('Y-m-d H:i:s', (int) strtotime($now)));
            if ($shipmentId > 0 && $failure === null) {
                $job->setData('magento_shipment_id', $shipmentId);
            }
        }

        $trackingIds = array_values(array_unique(array_filter(array_column($new, 'id'), static fn ($v) => $v !== '')));
        $job->appendHistory(array_filter([
            'event'        => $plan['pre_send'] ? 'dropship_tracking_recorded' : 'shipped',
            'status'       => $plan['pre_send'] ? null : PdfJob::STATUS_SHIPPED,
            'tracking_id'  => $trackingIds !== [] ? implode(', ', $trackingIds) : null,
            'send'         => (int) $job->getData('dropship_send_count'),
            'shipment_id'  => $shipmentId > 0 ? $shipmentId : null,
            'track_added'  => $plan['mode'] === self::MODE_TRACK && ($plan['tracks_added'] ?? []) !== [] ? true : null,
            'warning'      => $plan['warning'],
            'note'         => $plan['note'],
            'error'        => $failure,
        ], static fn ($v) => $v !== null));
        $this->pdfJobRepository->save($job);

        $label = $trackingIds !== [] ? implode(', ', $trackingIds) : 'n/a';
        $urls  = array_values(array_unique(array_filter(array_column($new, 'url'), static fn ($v) => $v !== '')));
        $comment = $plan['pre_send']
            ? sprintf('Printess dropship: tracking %s received for %s', $label, $plan['sku'])
            : sprintf('Printess dropship: %s shipped, tracking %s', $plan['sku'], $label);
        if ($urls !== []) {
            $comment .= ' (' . implode(', ', $urls) . ')';
        }
        if ($plan['note'] !== null) {
            $comment .= '; ' . $plan['note'];
        }
        if ($failure !== null) {
            $comment .= $plan['mode'] === self::MODE_TRACK
                ? sprintf('. The tracking number could not be added to shipment #%d automatically (%s); please add it manually.', $shipmentId, $failure)
                : sprintf('. The Magento shipment could not be created automatically (%s); please create it manually.', $failure);
        }
        return $comment . '.';
    }

    /**
     * @param array<int, array> $plans the jobs to ship (mode ship)
     * @return int new shipment entity id
     */
    private function createShipment(int $orderId, array $plans, bool $notify): int
    {
        $shipItems = [];
        $tracks    = [];
        $urls      = [];
        foreach ($plans as $plan) {
            $itemId = (int) $plan['item_id'];
            if (!isset($shipItems[$itemId])) {
                $shipItem = $this->itemCreationFactory->create();
                $shipItem->setOrderItemId($itemId);
                $shipItem->setQty($plan['qty']);
                $shipItems[$itemId] = $shipItem;
            }
            foreach ($plan['new'] as $report) {
                if ($report['id'] !== '' && !isset($tracks[$report['id']])) {
                    $track = $this->trackCreationFactory->create();
                    $track->setCarrierCode(self::CARRIER_CODE);
                    $track->setTitle($report['type'] ?? self::DEFAULT_TITLE);
                    $track->setTrackNumber($report['id']);
                    $tracks[$report['id']] = $track;
                }
                if ($report['url'] !== '') {
                    $urls[$report['url']] = true;
                }
            }
        }

        $commentText = 'Shipped by the dropshipper.';
        if ($urls !== []) {
            $commentText .= ' Track your parcel: ' . implode(', ', array_keys($urls));
        }
        $comment = $this->commentCreationFactory->create();
        $comment->setComment($commentText);
        $comment->setIsVisibleOnFront(1);

        return (int) $this->shipOrder->execute(
            $orderId,
            array_values($shipItems),
            $notify,
            true,
            $comment,
            array_values($tracks)
        );
    }

    /**
     * Adds the new tracks of a re-shipped item to the shipment created earlier and, when "Send shipment email" is
     * on, tells the customer through the shipment-comment email (the quantity is already shipped, so Magento
     * would not send a new shipment email).
     *
     * @param list<array{id: string, url: string, type: ?string}> $reports
     * @return array{error: ?string, added: list<string>} error message (null on success) and the tracking numbers
     *     actually added (a number already on the shipment is not added again and triggers no email)
     */
    private function addTracksToShipment(int $shipmentId, array $reports, ?int $storeId): array
    {
        try {
            $shipment = $this->shipmentRepository->get($shipmentId);
            if (!$shipment instanceof Shipment || !$shipment->getId()) {
                return ['error' => sprintf('shipment %d not found', $shipmentId), 'added' => []];
            }
            $known = [];
            foreach ($shipment->getAllTracks() as $existing) {
                $known[(string) $existing->getTrackNumber()] = true;
            }

            $added = [];
            $urls  = [];
            foreach ($reports as $report) {
                if ($report['url'] !== '') {
                    $urls[$report['url']] = true;
                }
                if ($report['id'] === '' || isset($known[$report['id']])) {
                    continue;
                }
                $track = $this->trackFactory->create();
                $track->setNumber($report['id']);
                $track->setTitle($report['type'] ?? self::DEFAULT_TITLE);
                $track->setCarrierCode(self::CARRIER_CODE);
                $shipment->addTrack($track);
                $known[$report['id']] = true;
                $added[] = $report['id'];
            }
            if ($added === []) {
                return ['error' => null, 'added' => []]; // the tracking number is already on the shipment
            }

            $notify = $this->config->isSendShipmentEmail($storeId);
            $text = 'Shipped again by the dropshipper. New tracking number: ' . implode(', ', $added);
            if ($urls !== []) {
                $text .= '. Track your parcel: ' . implode(', ', array_keys($urls));
            }
            $shipment->addComment($text, $notify, true);
            $this->shipmentRepository->save($shipment);
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('Printess dropship: could not add track to shipment %d: %s', $shipmentId, $e->getMessage()),
                ['exception' => $e]
            );
            return ['error' => $e->getMessage(), 'added' => []];
        }

        if ($notify) {
            try {
                $this->shipmentCommentSender->send($shipment, true, $text);
            } catch (\Throwable $e) {
                // The track is saved; a mail problem must not fail the callback.
                $this->logger->error(
                    sprintf('Printess dropship: could not send the tracking update email for shipment %d: %s', $shipmentId, $e->getMessage()),
                    ['exception' => $e]
                );
            }
        }
        return ['error' => null, 'added' => $added];
    }

    private function qtyToShip(OrderItemInterface $item): float
    {
        if ($item instanceof OrderItem) {
            return (float) $item->getQtyToShip();
        }
        return max(0.0, (float) $item->getQtyOrdered() - (float) $item->getQtyShipped()
            - (float) $item->getQtyRefunded() - (float) $item->getQtyCanceled());
    }

    private function str(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return '';
    }
}
