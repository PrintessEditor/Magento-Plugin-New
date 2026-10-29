<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Production;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Config as OrderConfig;
use Magento\Sales\Model\Order\Status\HistoryFactory;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Printess\PrintessEditor\Model\PdfJobRepository;
use Psr\Log\LoggerInterface;

/**
 * The single order-level writer of Printess production statuses and comments.
 *
 * It only ever changes the order *status* within the current state — never the state — so production progress
 * (and errors) are visible without interfering with Magento's own invoice/ship/complete flow. A production error
 * is shown as a status but never holds or cancels the order (D9). Comments are always written, whatever the state.
 *
 * The order is never saved as a whole: a stale full save could overwrite state/totals written concurrently by an
 * admin invoice or shipment. Only the `status` column is persisted (saveAttribute) after re-reading state/status
 * from the database, and comments go through the status history repository.
 *
 * Never throws: callers are cron jobs and callbacks that must keep processing other jobs.
 */
class OrderStageUpdater
{
    /** States in which a Printess status may be shown (statuses are assigned to these by the data patch). */
    private const ELIGIBLE_STATES = [Order::STATE_NEW, Order::STATE_PROCESSING];

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly PdfJobRepository $pdfJobRepository,
        private readonly OrderConfig $orderConfig,
        private readonly OrderStageCalculator $calculator,
        private readonly OrderResource $orderResource,
        private readonly OrderStatusHistoryRepositoryInterface $historyRepository,
        private readonly HistoryFactory $historyFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function update(int $orderId, ?string $comment = null): void
    {
        try {
            $order = $this->orderRepository->get($orderId);
            if (!$order instanceof Order) {
                return;
            }

            $jobs = [];
            foreach ($this->pdfJobRepository->getByOrderId($orderId) as $job) {
                $jobs[] = [
                    'production_type' => $job->getData('production_type'),
                    'status'          => $job->getData('status'),
                    'dropship_state'  => $job->getData('dropship_state'),
                ];
            }
            $stage = $this->calculator->calculate($jobs);

            // Fresh state/status right before writing (the loaded order may be cached or stale).
            [$state, $currentStatus] = $this->readStateAndStatus($order);

            $targetStatus = in_array($state, self::ELIGIBLE_STATES, true)
                ? $this->resolveTargetStatus($state, $currentStatus, $stage)
                // complete/closed/canceled/holded etc. belong to Magento or the merchant — status left alone.
                : $currentStatus;

            if ($targetStatus !== $currentStatus) {
                $order->setStatus($targetStatus);
                $this->orderResource->saveAttribute($order, 'status');
                $this->syncGridStatus($orderId, $targetStatus);
            }

            $comment = $comment !== null ? trim($comment) : '';
            if ($comment !== '') {
                $this->writeComment($orderId, $comment, $targetStatus);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('Printess: failed to update production status of order %d: %s', $orderId, $e->getMessage()),
                ['exception' => $e]
            );
        }
    }

    /** Adds an internal order comment without touching the order status. Never throws. */
    public function addComment(int $orderId, string $comment): void
    {
        $comment = trim($comment);
        if ($comment === '') {
            return;
        }
        try {
            $order = $this->orderRepository->get($orderId);
            if (!$order instanceof Order) {
                return;
            }
            [, $status] = $this->readStateAndStatus($order);
            $this->writeComment($orderId, $comment, $status);
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('Printess: failed to add comment to order %d: %s', $orderId, $e->getMessage()),
                ['exception' => $e]
            );
        }
    }

    /**
     * @return array{0: string, 1: string} [state, status] as currently stored
     */
    private function readStateAndStatus(Order $order): array
    {
        $connection = $this->orderResource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->orderResource->getMainTable(), ['state', 'status'])
                ->where($this->orderResource->getIdFieldName() . ' = ?', (int) $order->getId())
        );
        if (!is_array($row)) {
            return [(string) $order->getState(), (string) $order->getStatus()];
        }
        // Keep the in-memory model consistent with what is stored.
        $order->setData('state', $row['state']);
        $order->setData('status', $row['status']);
        return [(string) $row['state'], (string) $row['status']];
    }

    private function writeComment(int $orderId, string $comment, string $status): void
    {
        $history = $this->historyFactory->create();
        $history->setParentId($orderId);
        $history->setComment($comment);
        $history->setStatus($status !== '' ? $status : null);
        $history->setIsCustomerNotified(0);
        $history->setIsVisibleOnFront(0);
        $history->setEntityName('order');
        $this->historyRepository->save($history);
    }

    /**
     * saveAttribute bypasses the resource save events that keep sales_order_grid in sync; mirror the status there
     * so the admin order grid does not show a stale value. Best effort.
     */
    private function syncGridStatus(int $orderId, string $status): void
    {
        try {
            $this->orderResource->getConnection()->update(
                $this->orderResource->getTable('sales_order_grid'),
                ['status' => $status],
                ['entity_id = ?' => $orderId]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                'Printess: could not update order grid status of order %d: %s',
                $orderId,
                $e->getMessage()
            ));
        }
    }

    /**
     * Status the order should carry; returns $currentStatus when it must stay unchanged.
     */
    private function resolveTargetStatus(string $state, string $currentStatus, ?string $stage): string
    {
        if ($stage === null) {
            // No Printess stage: only undo our own statuses, never a merchant/third-party custom status.
            if (in_array($currentStatus, OrderStage::ALL, true)) {
                $default = (string) $this->orderConfig->getStateDefaultStatus($state);
                return $default !== '' ? $default : $currentStatus;
            }
            return $currentStatus;
        }

        $assigned = $this->orderConfig->getStateStatuses($state);
        if (!is_array($assigned) || !array_key_exists($stage, $assigned)) {
            // e.g. PARTIALLY_SHIPPED while still `new`, or a merchant removed the assignment.
            $this->logger->info(sprintf(
                'Printess: status "%s" is not assigned to order state "%s"; order status left as "%s".',
                $stage,
                $state,
                $currentStatus
            ));
            return $currentStatus;
        }

        return $stage;
    }
}
