<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Printess\PrintessEditor\Model\Production\ReproductionContext;

/**
 * sales_model_service_quote_submit_before, for the order copy in progress only (PMD-21):
 *  - writes the pending order data from ReproductionContext onto the order before it is placed — the preliminary
 *    `printess_reproduction_of` JSON (so the column is set when EnqueuePdfJobs runs on quote_submit_success) and the
 *    original's `shipping_method`, so the shipping code sent to Printess stays the original's;
 *  - suppresses the new-order email (Magento\Quote\Observer\SubmitObserver checks the flag).
 *
 * Takes the place of a fieldset.xml entry: quote.printess_reproduction_of is an int, the order column is JSON.
 */
class PrepareReproductionOrder implements ObserverInterface
{
    public function __construct(private readonly ReproductionContext $context)
    {
    }

    public function execute(Observer $observer): void
    {
        $quote = $observer->getEvent()->getData('quote');
        $order = $observer->getEvent()->getData('order');
        if (!is_object($quote) || !is_object($order) || !method_exists($quote, 'getData')) {
            return;
        }
        if (!$this->context->isActiveFor($quote->getData('printess_reproduction_of'))) {
            return;
        }

        foreach ($this->context->getPendingOrderData() as $key => $value) {
            if ($value !== null && $value !== '') {
                $order->setData($key, $value);
            }
        }
        if (method_exists($order, 'setCanSendNewEmailFlag')) {
            $order->setCanSendNewEmailFlag(false);
        }
        $order->setData('send_email', 0);
        $order->setData('customer_note_notify', 0);
    }
}
