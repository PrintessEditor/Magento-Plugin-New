<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Observer;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Printess\PrintessEditor\Model\Approval\ApprovalGate;
use Printess\PrintessEditor\Model\Approval\QuoteDesigns;

/**
 * SUP-13: last line of defence before an order is placed (onepage, multishipping, REST/GraphQL): every Printess design
 * in the quote must be approved for the customer. Catches stale carts and API adds. Admin orders are exempt.
 *
 * Events: sales_model_service_quote_submit_before, checkout_type_multishipping_create_orders_single (data: quote).
 */
class AssertApprovedDesignsOnCheckout implements ObserverInterface
{
    public function __construct(
        private readonly ApprovalGate $approvalGate,
        private readonly QuoteDesigns $quoteDesigns,
        private readonly State $appState
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            if ($this->appState->getAreaCode() === Area::AREA_ADMINHTML) {
                return;
            }
        } catch (\Throwable) {
            // no area: treat as storefront
        }
        $quote = $observer->getData('quote');
        if (!$quote || $quote->getData('printess_reproduction_of')) {
            return;
        }
        $customerId = (int) $quote->getCustomerId();
        $storeId = (int) $quote->getStoreId() ?: null;
        if (!$this->approvalGate->needsApproval($customerId, $storeId)) {
            return;
        }
        foreach ($quote->getAllVisibleItems() as $item) {
            $token = $this->quoteDesigns->getSaveToken($item);
            if ($token !== '' && !$this->approvalGate->isTokenApproved($customerId, $token)) {
                throw new LocalizedException(__(
                    '"%1" contains a design that has not been approved by a supervisor. Remove it from the cart or submit it for approval.',
                    (string) $item->getName()
                ));
            }
        }
    }
}
