<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Plugin\Payment;

use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Payment\Model\Method\Free;
use Magento\Quote\Api\Data\CartInterface;
use Printess\PrintessEditor\Model\Production\ReproductionContext;

/**
 * Zero Subtotal Checkout (`free`) pays the order copies (PMD-21). It is active by default, but a merchant may have
 * disabled it for the storefront; for the copy in progress — and only for it — it is available whenever the quote
 * total is 0, regardless of that setting. Every other quote keeps the core result.
 */
class AllowFreeForReproduction
{
    public function __construct(
        private readonly ReproductionContext $context,
        private readonly PriceCurrencyInterface $priceCurrency
    ) {
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterIsAvailable(Free $subject, $result, ?CartInterface $quote = null)
    {
        if ($result || $quote === null || !method_exists($quote, 'getData')) {
            return $result;
        }
        if (!$this->context->isActiveFor($quote->getData('printess_reproduction_of'))) {
            return $result;
        }
        return $this->priceCurrency->round((float) $quote->getGrandTotal()) == 0
            && $this->priceCurrency->round((float) $quote->getBaseGrandTotal()) == 0;
    }
}
