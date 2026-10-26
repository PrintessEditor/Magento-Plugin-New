<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Production;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Printess\PrintessEditor\Helper\Config;
use Printess\PrintessEditor\Model\PdfJob;
use Printess\PrintessEditor\Model\PdfJobRepository;
use Psr\Log\LoggerInterface;

/**
 * Cron phase 0: moves awaiting_payment jobs on once their order items are invoiced.
 *
 * Polling qty_invoiced (instead of only listening to sales_order_invoice_pay) also covers online
 * capture, where the invoice is paid inside Order::place() before any job exists.
 *
 * Print jobs are released per item. Dropship jobs of one order are released only together, when
 * every awaiting dropship item is invoiced, because Printess waits for all linked items before it
 * submits the combined order — a partially invoiced order therefore stays held.
 */
class PaymentReleaser
{
    private const BATCH_LIMIT = 200;
    /** Upper bound of awaiting_payment jobs inspected per cron run (keyset-paged by job_id). */
    private const MAX_PER_RUN = 2000;

    public function __construct(
        private readonly PdfJobRepository $pdfJobRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /** @return int number of jobs released */
    public function releasePaidJobs(): int
    {
        // Walk the whole queue with a job_id cursor: unpaid jobs at the head must not starve newer ones.
        $byOrder = [];
        $cursor = 0;
        $seen = 0;
        while ($seen < self::MAX_PER_RUN) {
            $page = $this->pdfJobRepository->getByStatusAfterId(
                PdfJob::STATUS_AWAITING_PAYMENT,
                $cursor,
                min(self::BATCH_LIMIT, self::MAX_PER_RUN - $seen)
            );
            if ($page === []) {
                break;
            }
            foreach ($page as $job) {
                $byOrder[(int)$job->getData('order_id')][] = $job;
                $cursor = max($cursor, (int)$job->getId());
            }
            $seen += count($page);
            if (count($page) < self::BATCH_LIMIT) {
                break;
            }
        }

        $released = 0;
        foreach ($byOrder as $orderId => $jobs) {
            try {
                $released += $this->releaseOrder($orderId, $jobs);
            } catch (\Throwable $e) {
                $this->logger->error('Printess: payment release failed for order', [
                    'order_id' => $orderId,
                    'error'    => $e->getMessage(),
                ]);
            }
        }
        return $released;
    }

    /**
     * @param PdfJob[] $batchJobs awaiting_payment jobs of this order found in the current batch
     */
    private function releaseOrder(int $orderId, array $batchJobs): int
    {
        $order = $this->orderRepository->get($orderId);
        if (in_array((string)$order->getState(), [Order::STATE_CANCELED, Order::STATE_CLOSED], true)) {
            return $this->failCanceled($order, $batchJobs);
        }
        $items = [];
        foreach ((array)$order->getItems() as $item) {
            $items[(int)$item->getItemId()] = $item;
        }
        $targetStatus = $this->config->isApproveBeforeProduction((int)$order->getStoreId())
            ? PdfJob::STATUS_AWAITING_APPROVAL
            : PdfJob::STATUS_PENDING;

        $released = 0;
        $hasDropship = false;
        foreach ($batchJobs as $job) {
            if ($job->isDropship()) {
                $hasDropship = true;
                continue;
            }
            if ($this->isPaid($items[(int)$job->getData('order_item_id')] ?? null)) {
                $released += $this->release($job, $targetStatus, $order);
            }
        }

        if ($hasDropship) {
            // Re-read from the order so the batch limit can never split a linked group.
            $dropshipJobs = array_filter(
                $this->pdfJobRepository->getByOrderId($orderId),
                static fn (PdfJob $j): bool => $j->isDropship()
                    && (string)$j->getData('status') === PdfJob::STATUS_AWAITING_PAYMENT
            );
            foreach ($dropshipJobs as $job) {
                if (!$this->isPaid($items[(int)$job->getData('order_item_id')] ?? null)) {
                    return $released; // hold the whole linked group
                }
            }
            foreach ($dropshipJobs as $job) {
                $released += $this->release($job, $targetStatus, $order);
            }
        }

        return $released;
    }

    /** A missing item (deleted / inconsistent order) is treated as unpaid so nothing ships by accident. */
    private function isPaid(?OrderItemInterface $item): bool
    {
        return $item !== null && (float)$item->getQtyInvoiced() >= (float)$item->getQtyOrdered();
    }

    private function release(PdfJob $job, string $targetStatus, OrderInterface $order): int
    {
        $job->setData('status', $targetStatus);
        $job->appendHistory(['event' => 'payment_released', 'status' => $targetStatus]);
        $this->pdfJobRepository->save($job);
        $this->logger->info('Printess: job released after payment', [
            'job_id'       => $job->getId(),
            'order_id'     => $order->getEntityId(),
            'order_number' => $order->getIncrementId(),
            'status'       => $targetStatus,
        ]);
        return 1;
    }

    /**
     * A canceled/closed order will never be paid: take its held jobs out of the queue for good.
     *
     * @param PdfJob[] $batchJobs
     * @return int always 0 (nothing released)
     */
    private function failCanceled(OrderInterface $order, array $batchJobs): int
    {
        // Include awaiting jobs of this order outside the current batch (e.g. linked dropship siblings).
        $jobs = [];
        foreach ($batchJobs as $job) {
            $jobs[(int)$job->getId()] = $job;
        }
        foreach ($this->pdfJobRepository->getByOrderId((int)$order->getEntityId()) as $job) {
            if ((string)$job->getData('status') === PdfJob::STATUS_AWAITING_PAYMENT) {
                $jobs[(int)$job->getId()] = $job;
            }
        }
        foreach ($jobs as $job) {
            if ((string)$job->getData('status') !== PdfJob::STATUS_AWAITING_PAYMENT) {
                continue;
            }
            $job->setData('status', PdfJob::STATUS_FAILED);
            $job->setData('error_code', 'order_canceled');
            $job->setData('error_message', 'Order was ' . (string)$order->getState() . ' before payment.');
            $job->appendHistory([
                'event'       => 'order_canceled',
                'status'      => PdfJob::STATUS_FAILED,
                'order_state' => (string)$order->getState(),
            ]);
            $this->pdfJobRepository->save($job);
            $this->logger->info('Printess: awaiting_payment job dropped, order canceled/closed', [
                'job_id'       => $job->getId(),
                'order_id'     => $order->getEntityId(),
                'order_number' => $order->getIncrementId(),
                'order_state'  => (string)$order->getState(),
            ]);
        }
        return 0;
    }
}
