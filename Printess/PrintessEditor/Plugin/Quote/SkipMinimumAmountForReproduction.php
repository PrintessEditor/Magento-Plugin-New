<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Plugin\Quote;

use Magento\Quote\Model\Quote;
use Printess\PrintessEditor\Model\Production\ReproductionContext;

/**
 * An order copy (PMD-21) totals 0, so "Minimum Order Amount" would refuse it. Skipped for the copy in progress only.
 */
class SkipMinimumAmountForReproduction
{
    public function __construct(private readonly ReproductionContext $context)
    {
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterValidateMinimumAmount(Quote $subject, $result, $multishipping = false)
    {
        if ($result || !$this->context->isActiveFor($subject->getData('printess_reproduction_of'))) {
            return $result;
        }
        return true;
    }
}
