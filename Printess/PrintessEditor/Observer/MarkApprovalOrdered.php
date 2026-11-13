<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Printess\PrintessEditor\Model\Approval\ApprovalService;
use Printess\PrintessEditor\Model\Approval\QuoteDesigns;
use Printess\PrintessEditor\Model\Approval\SubmissionRepository;
use Psr\Log\LoggerInterface;

/**
 * SUP-13: once an order with an approved design is placed, the submission moves to "ordered" with the order id.
 * Events: sales_model_service_quote_submit_success (order), checkout_submit_all_after (order / orders).
 * Runs after the order is saved; never throws.
 */
class MarkApprovalOrdered implements ObserverInterface
{
    public function __construct(
        private readonly SubmissionRepository $repository,
        private readonly ApprovalService $approvalService,
        private readonly QuoteDesigns $quoteDesigns,
        private readonly LoggerInterface $logger,
        private readonly \Magento\Sales\Api\OrderItemRepositoryInterface $orderItemRepository
    ) {
    }

    public function execute(Observer $observer): void
    {
        $orders = $observer->getData('orders') ?: [];
        if ($observer->getData('order')) {
            $orders[] = $observer->getData('order');
        }
        foreach ($orders as $order) {
            if (!$order instanceof Order || !$order->getCustomerId()) {
                continue;
            }
            foreach ($order->getAllVisibleItems() as $item) {
                $token = $this->quoteDesigns->getSaveToken($item);
                if ($token === '') {
                    continue;
                }
                try {
                    $submission = $this->repository->findApprovedByToken((int) $order->getCustomerId(), $token);
                    if ($submission !== null) {
                        $this->approvalService->markOrdered($submission, (int) $order->getId());
                        $this->snapshotApproval($item, $submission);
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('Printess approval: could not mark design as ordered', [
                        'order_id' => (int) $order->getId(),
                        'error'    => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * SUP-17: keep who approved the design on the order item itself, so it survives deleting the customer or
     * supervisor. The "Printess " label prefix hides it from customer-facing order views and emails.
     */
    private function snapshotApproval(\Magento\Sales\Model\Order\Item $item, \Printess\PrintessEditor\Model\Approval\Submission $submission): void
    {
        $options = $item->getProductOptions() ?: [];
        $options['additional_options'] = $options['additional_options'] ?? [];
        $value = (string) __(
            'Approved by %1 on %2 UTC, round %3%4',
            (string) ($submission->getData('approved_by_name') ?: __('a supervisor')),
            (string) $submission->getData('approved_at'),
            (int) $submission->getData('round'),
            $submission->getData('supervisor_edited') ? ', ' . __('changed by the supervisor') : ''
        );
        $options['additional_options']['printess_approval'] = [
            'label' => 'Printess Approval',
            'value' => $value,
        ];
        $item->setProductOptions($options);
        $this->orderItemRepository->save($item);
    }
}
