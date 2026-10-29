<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Sales\Model\Order\Item as OrderItem;
use Printess\PrintessEditor\Model\Cart\DesignPagePricing;
use Printess\PrintessEditor\Model\Cart\ReorderContext;
use Psr\Log\LoggerInterface;

/**
 * PMD-23, admin "Reorder" / "Edit": AdminOrder\Create::initFromOrderItem already copies additional_options (the
 * Printess design) but rebuilds the item from the buy request at the plain product price, so the Printess page
 * surcharge of the original add-to-cart was lost. Re-applies it server-side like the add-to-cart does (PR #8):
 * page count, included pages and form fields from /book/info for the design, option/variant values from the order
 * item's info_buyRequest.
 */
class ApplyPagePricingOnAdminReorder implements ObserverInterface
{
    public function __construct(
        private readonly DesignPagePricing $designPagePricing,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $orderItem = $observer->getEvent()->getData('order_item');
        $quoteItem = $observer->getEvent()->getData('quote_item');
        if (!$orderItem instanceof OrderItem || !$quoteItem instanceof QuoteItem) {
            return;
        }

        try {
            $printess = ReorderContext::extractPrintessOptions($orderItem->getProductOptionByCode('additional_options'));
            if ($printess === []) {
                return;
            }
            $buyRequest = $orderItem->getBuyRequest();
            // An admin-set custom price on the item wins over the Printess page surcharge.
            $customPrice = $buyRequest->getCustomPrice();
            if ($customPrice !== null && $customPrice !== '') {
                return;
            }
            $this->designPagePricing->applyFromDesign(
                $quoteItem->getParentItem() ?: $quoteItem,
                (string) $printess['printess_save_token']['value'],
                (int) $orderItem->getStoreId() ?: null,
                (array) $buyRequest->getData()
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Printess: page pricing on admin reorder failed', ['error' => $e->getMessage()]);
        }
    }
}
