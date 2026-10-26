<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Printess\PrintessEditor\Model\Production\ReproductionStockCompensator;
use Psr\Log\LoggerInterface;

/**
 * order_cancel_after, for order copies created with "Reduce stock" off only (PMD-21): Magento gives the cancelled qty
 * back to stock, although the copy never took it (its reservations were compensated at placement). Takes it away
 * again so the salable qty ends where it was before the copy. Never breaks the cancellation; failures are logged.
 */
class CompensateReproductionCancel implements ObserverInterface
{
    private const DONE_FLAG = 'printess_reproduction_cancel_compensated';

    public function __construct(
        private readonly ReproductionStockCompensator $compensator,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        if (!$order instanceof Order || $order->getData(self::DONE_FLAG)) {
            return;
        }
        if (!ReproductionStockCompensator::isCopyWithoutStockReduction($order->getData('printess_reproduction_of'))) {
            return;
        }
        $order->setData(self::DONE_FLAG, true);
        try {
            $this->compensator->compensateCancel($order);
        } catch (\Throwable $e) {
            $this->logger->error('Printess: could not compensate the stock of a cancelled order copy; adjust it manually', [
                'order_id' => (int) $order->getEntityId(), 'error' => $e->getMessage(), 'exception' => $e,
            ]);
        }
    }
}
