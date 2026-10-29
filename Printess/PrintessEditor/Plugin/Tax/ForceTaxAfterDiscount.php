<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Plugin\Tax;

use Magento\Tax\Model\Config;
use Printess\PrintessEditor\Model\Production\ReproductionContext;

/**
 * While an order copy is being built (ReproductionContext active), tax is calculated after discount, so the
 * `printess_reproduction` discount brings tax to 0 in every tax configuration (S1-report.md §3, fix b).
 * The window is kept tight: OrderReproductionService starts the context right before building the quote and stops
 * it right after submitting it.
 */
class ForceTaxAfterDiscount
{
    public function __construct(private readonly ReproductionContext $context)
    {
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterApplyTaxAfterDiscount(Config $subject, $result)
    {
        return $this->context->isActive() ? true : $result;
    }
}
