<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Quote\Total;

use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Address\Total\AbstractTotal;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\Tax\Model\Config as TaxConfig;
use Printess\PrintessEditor\Model\Production\ReproductionContext;
use Printess\PrintessEditor\Model\Production\ReproductionDiscountCalculator;
use Psr\Log\LoggerInterface;

/**
 * `printess_reproduction` total (sort_order 420, see etc/sales.xml and S1-report.md §3/§6).
 *
 * Runs after SalesRule's discount (300) and shipping discount (400), which would otherwise reset it, and before tax
 * (450), which reads each item's discount_amount. Only active for the quote of an order copy in progress
 * (`quote.printess_reproduction_of` = the ReproductionContext's original order id). It REPLACES any discount on the
 * items by one that offsets them exactly (ReproductionDiscountCalculator): the gross row total when catalog prices
 * include tax, the net row total otherwise. With tax applied after discount (forced by
 * Plugin\Tax\ForceTaxAfterDiscount while the context is active) tax and grand total both come to 0.
 * OrderReproductionService asserts that and falls back to items at custom price 0 when it does not hold.
 */
class Reproduction extends AbstractTotal
{
    public const CODE = 'printess_reproduction';

    /** quote_address.discount_description and sales_order.discount_description are varchar(255). */
    public const DISCOUNT_LABEL_MAX_LENGTH = 255;

    public function __construct(
        private readonly ReproductionContext $context,
        private readonly ReproductionDiscountCalculator $calculator,
        private readonly TaxConfig $taxConfig,
        private readonly LoggerInterface $logger
    ) {
        $this->setCode(self::CODE);
    }

    public function collect(Quote $quote, ShippingAssignmentInterface $shippingAssignment, Total $total)
    {
        parent::collect($quote, $shippingAssignment, $total);

        if (!$this->context->isDiscountEnabled() || !$this->context->isActiveFor($quote->getData('printess_reproduction_of'))) {
            return $this;
        }
        $items = $shippingAssignment->getItems();
        if (!$items) {
            return $this;
        }
        $address = $shippingAssignment->getShipping()->getAddress();

        /** @var array<int, AbstractItem> $targets items that carry the amounts (children when calculated separately) */
        $targets = [];
        $parents = [];
        foreach ($items as $item) {
            if ($item->getParentItem()) {
                continue;
            }
            if ($item->getHasChildren() && $item->isChildrenCalculated()) {
                $parents[] = $item;
                foreach ($item->getChildren() as $child) {
                    $targets[] = $child;
                }
            } else {
                $targets[] = $item;
            }
        }
        if ($targets === []) {
            return $this;
        }

        $rows = [];
        foreach ($targets as $index => $item) {
            $rowTotal = (float) $item->getRowTotal();
            $baseRowTotal = (float) $item->getBaseRowTotal();
            $rows[$index] = [
                'id'                      => $index,
                'qty'                     => (float) ($item->getTotalQty() ?: $item->getQty()) ?: 1.0,
                'row_total'               => $rowTotal,
                'row_total_incl_tax'      => $item->getRowTotalInclTax() !== null
                    ? max($rowTotal, (float) $item->getRowTotalInclTax()) : $rowTotal,
                'base_row_total'          => $baseRowTotal,
                'base_row_total_incl_tax' => $item->getBaseRowTotalInclTax() !== null
                    ? max($baseRowTotal, (float) $item->getBaseRowTotalInclTax()) : $baseRowTotal,
                'tax_percent'             => max(0.0, (float) $item->getTaxPercent()),
            ];
        }

        $rate = (float) $quote->getBaseToQuoteRate();
        try {
            $result = $this->calculator->calculate(
                $rows,
                (bool) $this->taxConfig->priceIncludesTax($quote->getStore()),
                $rate > 0 ? $rate : 1.0
            );
        } catch (\InvalidArgumentException $e) {
            // OrderReproductionService sees a non-zero total and falls back to custom price 0.
            $this->logger->warning('Printess: reproduction discount could not be calculated', [
                'quote_id' => $quote->getId(),
                'error'    => $e->getMessage(),
            ]);
            return $this;
        }

        foreach ($parents as $parent) {
            $this->clearItemDiscount($parent);
        }
        foreach ($targets as $index => $item) {
            $line = $result['items'][$index];
            $item->setAppliedRuleIds(null);
            $this->clearRuleDiscounts($item);
            $item->setDiscountAmount($line['discount_amount']);
            $item->setBaseDiscountAmount($line['base_discount_amount']);
            $item->setOriginalDiscountAmount($line['discount_amount']);
            $item->setBaseOriginalDiscountAmount($line['base_discount_amount']);
            $item->setDiscountPercent($line['discount_percent']);
        }

        $discount = (float) $result['discount_amount'];
        $baseDiscount = (float) $result['base_discount_amount'];
        // The full message (up to 1000 characters) is the copy's order comment; the label must fit the column.
        $message = self::discountLabel($this->context->getMessage());

        // Replaces whatever SalesRule put into the discount total; tax adds discount_tax_compensation itself.
        $total->setTotalAmount('discount', -$discount);
        $total->setBaseTotalAmount('discount', -$baseDiscount);
        $total->setDiscountDescription($message);
        $total->setSubtotalWithDiscount(0);
        $total->setBaseSubtotalWithDiscount(0);
        $total->setShippingDiscountAmount(0);
        $total->setBaseShippingDiscountAmount(0);

        $address->setDiscountAmount(-$discount);
        $address->setBaseDiscountAmount(-$baseDiscount);
        $address->setDiscountDescription($message);
        $address->setSubtotalWithDiscount(0);
        $address->setBaseSubtotalWithDiscount(0);
        $address->setShippingDiscountAmount(0);
        $address->setBaseShippingDiscountAmount(0);
        $address->setAppliedRuleIds('');
        $address->setCouponCode('');
        if ($address->getExtensionAttributes() && method_exists($address->getExtensionAttributes(), 'setDiscounts')) {
            $address->getExtensionAttributes()->setDiscounts([]);
        }
        $quote->setAppliedRuleIds('');

        return $this;
    }

    /**
     * One discount row, titled with the merchant's message. It has the code `discount`, so it replaces the core
     * "Discount (…)" row instead of adding a second one (TotalsReader merges rows by code, later collectors win).
     */
    public function fetch(Quote $quote, Total $total)
    {
        if (!$this->context->isDiscountEnabled() || !$this->context->isActiveFor($quote->getData('printess_reproduction_of'))) {
            return null;
        }
        $amount = (float) $total->getDiscountAmount();
        if ($amount == 0.0) {
            return null;
        }
        $description = (string) $total->getDiscountDescription();
        return [
            'code'  => 'discount',
            'title' => $description !== '' ? $description : __('Discount'),
            'value' => $amount,
        ];
    }

    /** The merchant's message cut to DISCOUNT_LABEL_MAX_LENGTH characters (multibyte safe), ending in "…" if cut. */
    public static function discountLabel(string $message): string
    {
        $message = trim($message);
        if (mb_strlen($message, 'UTF-8') <= self::DISCOUNT_LABEL_MAX_LENGTH) {
            return $message;
        }
        return rtrim(mb_substr($message, 0, self::DISCOUNT_LABEL_MAX_LENGTH - 1, 'UTF-8')) . '…';
    }

    private function clearItemDiscount(AbstractItem $item): void
    {
        $item->setAppliedRuleIds(null);
        $this->clearRuleDiscounts($item);
        $item->setDiscountAmount(0);
        $item->setBaseDiscountAmount(0);
        $item->setOriginalDiscountAmount(0);
        $item->setBaseOriginalDiscountAmount(0);
        $item->setDiscountPercent(0);
    }

    private function clearRuleDiscounts(AbstractItem $item): void
    {
        $extension = $item->getExtensionAttributes();
        if ($extension && method_exists($extension, 'setDiscounts')) {
            $extension->setDiscounts(null);
        }
    }
}
