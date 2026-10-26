<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item as OrderItem;
use Printess\PrintessEditor\Helper\Config;
use Printess\PrintessEditor\Model\PdfJob;
use Printess\PrintessEditor\Model\PdfJobFactory;
use Printess\PrintessEditor\Model\PdfJobRepository;
use Printess\PrintessEditor\Model\Production\DropshipResolver;
use Printess\PrintessEditor\Model\Production\EnqueueFailureNote;
use Printess\PrintessEditor\Model\Production\InitialStatusPolicy;
use Printess\PrintessEditor\Model\Production\PrintSettingsResolver;
use Psr\Log\LoggerInterface;

/**
 * Fires on sales_model_service_quote_submit_success (after the order and its items are saved,
 * for storefront and admin-created orders alike) and on checkout_submit_all_after, which is the
 * only post-save event multishipping checkout dispatches (event data 'orders'; onepage passes 'order').
 *
 * For each visible order item with a Printess save token, creates one row in printess_pdf_jobs
 * with everything production needs snapshotted (store, copies, print settings, dropship
 * definition, callback mode, nonce), so later config/product edits do not change in-flight jobs.
 * The actual Printess calls happen asynchronously in Cron\ProducePdf.
 *
 * Idempotent: items that already have a job are skipped (checked against the database, not a
 * request memo), so the same order arriving via both events, or a re-dispatched event, cannot
 * create duplicate production (and thus duplicate physical shipments). Dropship jobs added on a
 * later pass reuse the order's existing linked_order_id.
 */
class EnqueuePdfJobs implements ObserverInterface
{
    public function __construct(
        private readonly PdfJobFactory $pdfJobFactory,
        private readonly PdfJobRepository $pdfJobRepository,
        private readonly Config $config,
        private readonly DropshipResolver $dropshipResolver,
        private readonly PrintSettingsResolver $printSettingsResolver,
        private readonly InitialStatusPolicy $initialStatusPolicy,
        private readonly OrderStatusHistoryRepositoryInterface $historyRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Order items already reported in an order comment during this request. Both events can fire for the same
     * order (and a failed item has no job, so it is retried on the second), which must not write the note twice.
     *
     * @var array<int, array<int, true>>
     */
    private array $reportedFailures = [];

    public function execute(Observer $observer): void
    {
        $event  = $observer->getEvent();
        $orders = $event->getData('orders');
        if (!is_array($orders)) {
            $orders = [$event->getData('order')];
        }

        // An order copy (PMD-21) carries the flag on its quote; the order column may only be written after placement.
        $quote = $event->getData('quote');
        $quoteIsReproduction = is_object($quote) && method_exists($quote, 'getData')
            && (int)$quote->getData('printess_reproduction_of') > 0;

        $seen = [];
        foreach ($orders as $order) {
            if (!$order instanceof Order || !$order->getId() || isset($seen[(int)$order->getId()])) {
                continue;
            }
            $seen[(int)$order->getId()] = true;
            $this->enqueueForOrder($order, $quoteIsReproduction || $this->isReproduction($order));
        }
    }

    /**
     * Orders created by OrderReproductionService (sales_order.printess_reproduction_of set) skip "hold until
     * payment" and "approve before production": the merchant created them on purpose (plan §1.5 step 9).
     */
    private function isReproduction(Order $order): bool
    {
        $value = $order->getData('printess_reproduction_of');
        return $value !== null && trim((string)$value) !== '' && trim((string)$value) !== '0';
    }

    private function enqueueForOrder(Order $order, bool $isReproduction = false): void
    {
        $orderId = (int)$order->getId();
        $storeId = (int)$order->getStoreId();

        $candidates = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $saveToken = $item->getProductOptions()['additional_options']['printess_save_token']['value'] ?? null;
            if (!empty($saveToken)) {
                $candidates[] = [$item, (string)$saveToken];
            }
        }
        if ($candidates === []) {
            return; // not a Printess order — no further cost
        }

        $existingItemIds = [];
        $linkedOrderId = null;
        try {
            foreach ($this->pdfJobRepository->getByOrderId($orderId) as $existing) {
                $existingItemIds[(int)$existing->getData('order_item_id')] = true;
                if ($existing->isDropship() && (string)$existing->getData('linked_order_id') !== '') {
                    // Keep one linked id per order even if the event is dispatched again.
                    $linkedOrderId = (string)$existing->getData('linked_order_id');
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Printess: could not load existing jobs, skipping enqueue', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);
            $failures = [];
            foreach ($candidates as [$item]) {
                $failures[(int)$item->getId()] = $this->failure($item, $e);
            }
            $this->reportFailures($order, $failures);
            return;
        }

        /** @var array<int, array{name: string, sku: string, error: string}> $failures keyed by order item id */
        $failures = [];

        $holdUntilPayment = $this->config->isHoldUntilPayment($storeId);
        $approve          = $this->config->isApproveBeforeProduction($storeId);
        $callbackMode     = $this->config->getProductionCallbackBaseUrl($storeId) !== ''
            ? PdfJob::CALLBACK_MODE_CALLBACK
            : PdfJob::CALLBACK_MODE_POLL;

        // Pass 1: resolve everything so linked dropship jobs can share one payment decision.
        $planned = [];
        $allDropshipPaid = true;
        foreach ($candidates as [$item, $saveToken]) {
            $itemId = (int)$item->getId();
            if (isset($existingItemIds[$itemId])) {
                continue;
            }
            try {
                $definitionId = $this->dropshipResolver->resolve($item, $storeId);
                $isPaid = $this->isItemPaid($item);
                $planned[] = [
                    'item'          => $item,
                    'save_token'    => $saveToken,
                    'definition_id' => $definitionId,
                    'print_settings' => $this->printSettingsResolver->resolve($item, $storeId),
                    'is_paid'       => $isPaid,
                ];
                if ($definitionId !== null && !$isPaid) {
                    $allDropshipPaid = false;
                }
            } catch (\Throwable $e) {
                $this->logEnqueueError($orderId, $itemId, $e);
                $failures[$itemId] = $this->failure($item, $e);
            }
        }

        // Pass 2: create the jobs.
        foreach ($planned as $plan) {
            /** @var OrderItem $item */
            $item = $plan['item'];
            $isDropship = $plan['definition_id'] !== null;
            try {
                if ($isDropship && $linkedOrderId === null) {
                    $linkedOrderId = bin2hex(random_bytes(16));
                }
                // Printess combines linked dropship jobs, so they share one payment state and are
                // released together (PaymentReleaser); print jobs are gated per item.
                $status = $this->initialStatusPolicy->decide(
                    $holdUntilPayment,
                    $isDropship ? $allDropshipPaid : $plan['is_paid'],
                    $approve,
                    $isReproduction
                );

                /** @var PdfJob $job */
                $job = $this->pdfJobFactory->create();
                $job->setData([
                    'order_id'              => $orderId,
                    'order_item_id'         => (int)$item->getId(),
                    'save_token'            => $plan['save_token'],
                    'store_id'              => $storeId,
                    'copies'                => max(1, (int)$item->getQtyOrdered()),
                    'print_settings'        => $plan['print_settings'] !== '' ? $plan['print_settings'] : null,
                    'production_type'       => $isDropship ? PdfJob::TYPE_DROPSHIP : PdfJob::TYPE_PRINT,
                    'product_definition_id' => $plan['definition_id'],
                    'callback_mode'         => $callbackMode,
                    'nonce'                 => bin2hex(random_bytes(32)),
                    'linked_order_id'       => $isDropship ? $linkedOrderId : null,
                    'status'                => $status,
                ]);
                $job->appendHistory($isReproduction
                    ? ['event' => 'enqueued', 'status' => $status, 'reproduction' => true]
                    : ['event' => 'enqueued', 'status' => $status]);
                $this->pdfJobRepository->save($job);
            } catch (\Throwable $e) {
                $this->logEnqueueError($orderId, (int)$item->getId(), $e);
                $failures[(int)$item->getId()] = $this->failure($item, $e);
            }
        }

        $this->reportFailures($order, $failures);
    }

    /** @return array{name: string, sku: string, error: string} */
    private function failure(OrderItem $item, \Throwable $e): array
    {
        return [
            'name'  => (string)$item->getName(),
            'sku'   => (string)$item->getSku(),
            'error' => $e->getMessage(),
        ];
    }

    /**
     * Adds one admin-only comment listing the items whose jobs could not be created (never notifies the customer,
     * never changes the order status). Best effort: a failure here is logged and must not break checkout.
     *
     * @param array<int, array{name: string, sku: string, error: string}> $failures keyed by order item id
     */
    private function reportFailures(Order $order, array $failures): void
    {
        $orderId = (int)$order->getId();
        $failures = array_diff_key($failures, $this->reportedFailures[$orderId] ?? []);
        if ($failures === []) {
            return;
        }

        try {
            $history = $order->addCommentToStatusHistory(EnqueueFailureNote::build(array_values($failures)), false, false);
            $history->setIsCustomerNotified(false);
            $this->historyRepository->save($history);
            foreach (array_keys($failures) as $itemId) {
                $this->reportedFailures[$orderId][$itemId] = true;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Printess: could not add the enqueue-failure comment to the order', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /** With online capture the invoice is saved together with the order, so this is already set. */
    private function isItemPaid(OrderItem $item): bool
    {
        return (float)$item->getQtyInvoiced() >= (float)$item->getQtyOrdered();
    }

    private function logEnqueueError(int $orderId, int $itemId, \Throwable $e): void
    {
        $this->logger->error('Printess: failed to enqueue PDF job for order item', [
            'order_id'      => $orderId,
            'order_item_id' => $itemId,
            'error'         => $e->getMessage(),
        ]);
    }
}
