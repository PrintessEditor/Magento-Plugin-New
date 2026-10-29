<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Printess\PrintessEditor\Model\Production\ReproductionStockCompensator;
use Psr\Log\LoggerInterface;

/**
 * sales_order_creditmemo_save_after, for order copies created with "Reduce stock" off only (PMD-21): items refunded
 * with "Return to Stock" are added back to stock by Magento, although the copy never took them. Takes that qty away
 * again. `back_to_stock` is a transient flag of the credit memo being created, so a later save of a loaded credit memo
 * does nothing; a second save of the same object in one request is skipped by a flag. Failures are logged only.
 */
class CompensateReproductionRefund implements ObserverInterface
{
    private const DONE_FLAG = 'printess_reproduction_refund_compensated';

    public function __construct(
        private readonly ReproductionStockCompensator $compensator,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $creditmemo = $observer->getEvent()->getData('creditmemo');
        if (!$creditmemo instanceof Creditmemo || $creditmemo->getData(self::DONE_FLAG)) {
            return;
        }
        $order = $creditmemo->getOrder();
        if (!$order instanceof Order
            || !ReproductionStockCompensator::isCopyWithoutStockReduction($order->getData('printess_reproduction_of'))
        ) {
            return;
        }
        $creditmemo->setData(self::DONE_FLAG, true);
        try {
            $this->compensator->compensateRefund($order, $creditmemo);
        } catch (\Throwable $e) {
            $this->logger->error('Printess: could not compensate the stock returned by a credit memo of an order copy; '
                . 'adjust it manually', [
                'order_id'      => (int) $order->getEntityId(),
                'creditmemo_id' => (int) $creditmemo->getEntityId(),
                'error'         => $e->getMessage(),
                'exception'     => $e,
            ]);
        }
    }
}
