<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Test\Unit\Model\Production;

use PHPUnit\Framework\TestCase;
use Printess\PrintessEditor\Model\Production\ReproductionDiscountCalculator;

class ReproductionDiscountCalculatorTest extends TestCase
{
    private ReproductionDiscountCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ReproductionDiscountCalculator();
    }

    /**
     * Mirrors Magento's row tax maths with "apply tax after discount" (AbstractAggregateCalculator):
     * incl: taxable = max(gross - D, 0), compensation = tax_before - tax_after
     * excl: taxable = max(net - D, 0), compensation = 0
     * grand = net + tax_after + compensation - D
     *
     * @return array{tax: float, grand: float, compensation: float}
     */
    private function simulateMagento(array $row, float $discount, bool $priceIncludesTax): array
    {
        $rate = $row['tax_percent'];
        $net = $row['row_total'];
        $gross = $row['row_total_incl_tax'];
        if ($priceIncludesTax) {
            $taxBefore = $gross - $net;
            $taxable = max($gross - $discount, 0);
            $taxAfter = round($taxable * $rate / (100 + $rate), 2);
            $compensation = $taxBefore - $taxAfter;
        } else {
            $taxable = max($net - $discount, 0);
            $taxAfter = round($taxable * $rate / 100, 2);
            $compensation = 0.0;
        }
        return [
            'tax' => $taxAfter,
            'compensation' => round($compensation, 2),
            'grand' => round($net + $taxAfter + $compensation - $discount, 2),
        ];
    }

    private function rows(): array
    {
        return [
            ['id' => 11, 'price' => 16.80, 'qty' => 3, 'row_total' => 50.42, 'row_total_incl_tax' => 59.99, 'tax_percent' => 19],
            ['id' => 12, 'price' => 9.35, 'qty' => 1, 'row_total' => 9.35, 'row_total_incl_tax' => 10.00, 'tax_percent' => 7],
            ['id' => 13, 'price' => 0.84, 'qty' => 7, 'row_total' => 5.88, 'row_total_incl_tax' => 7.00, 'tax_percent' => 19.0],
        ];
    }

    public function testPricesInclTaxDiscountIsGrossAndZeroesTaxAndGrandTotal(): void
    {
        $result = $this->calculator->calculate($this->rows(), true);

        self::assertSame(59.99, $result['items'][11]['discount_amount']);
        self::assertSame(59.99, $result['items'][11]['base_discount_amount']);
        self::assertSame(100.0, $result['items'][11]['discount_percent']);
        self::assertSame(9.57, $result['items'][11]['expected_discount_tax_compensation']);
        self::assertSame(76.99, $result['discount_amount']);

        foreach ($this->rows() as $row) {
            $sim = $this->simulateMagento($row, $result['items'][$row['id']]['discount_amount'], true);
            self::assertSame(0.0, $sim['tax'], 'tax for row ' . $row['id']);
            self::assertSame(0.0, $sim['grand'], 'grand for row ' . $row['id']);
            self::assertSame($result['items'][$row['id']]['expected_discount_tax_compensation'], $sim['compensation']);
        }
    }

    public function testPricesExclTaxDiscountIsNetAndZeroesTaxAndGrandTotal(): void
    {
        $result = $this->calculator->calculate($this->rows(), false);

        self::assertSame(50.42, $result['items'][11]['discount_amount']);
        self::assertSame(0.0, $result['items'][11]['expected_discount_tax_compensation']);
        self::assertSame(65.65, $result['discount_amount']);

        foreach ($this->rows() as $row) {
            $sim = $this->simulateMagento($row, $result['items'][$row['id']]['discount_amount'], false);
            self::assertSame(0.0, $sim['tax']);
            self::assertSame(0.0, $sim['grand']);
        }
    }

    public function testGrossDiscountOnExclPricesWouldLeaveNegativeGrandTotal(): void
    {
        // Documents why the offset basis must follow "catalog prices include tax", not "apply discount on incl".
        $row = $this->rows()[0];
        $sim = $this->simulateMagento($row, $row['row_total_incl_tax'], false);
        self::assertSame(0.0, $sim['tax']);
        self::assertSame(-9.57, $sim['grand']);
    }

    public function testPerTaxRateSummaryOffsetsSubtotalWithoutRemainder(): void
    {
        $result = $this->calculator->calculate($this->rows(), true);

        self::assertSame(['7', '19'], array_map('strval', array_keys($result['rates'])));
        $r19 = $result['rates']['19'];
        self::assertSame(19.0, $r19['tax_percent']);
        self::assertSame(56.30, $r19['net']);
        self::assertSame(66.99, $r19['gross']);
        self::assertSame(10.69, $r19['tax']);
        self::assertSame(66.99, $r19['discount']);
        self::assertSame(round($r19['net'] + $r19['tax'], 2), $r19['gross']);

        $sumOfRates = array_sum(array_column($result['rates'], 'discount'));
        self::assertSame($result['discount_amount'], round($sumOfRates, 2));
    }

    public function testFloatNoiseIsRoundedToMinorUnits(): void
    {
        $result = $this->calculator->calculate([
            ['id' => 1, 'qty' => 3, 'row_total' => 100.83999999999, 'row_total_incl_tax' => 120.0000000001, 'tax_percent' => 19],
            ['id' => 2, 'qty' => 1, 'row_total' => '0.1', 'row_total_incl_tax' => '0.2', 'tax_percent' => 100],
        ], true);

        self::assertSame(120.0, $result['items'][1]['discount_amount']);
        self::assertSame(19.16, $result['items'][1]['expected_discount_tax_compensation']);
        self::assertSame(0.2, $result['items'][2]['discount_amount']);
        self::assertSame(120.2, $result['discount_amount']);
    }

    public function testSuppliedBaseAmountsAreUsedAsIs(): void
    {
        $result = $this->calculator->calculate([
            ['id' => 1, 'qty' => 1, 'row_total' => 108.40, 'row_total_incl_tax' => 129.00, 'tax_percent' => 19,
                'base_row_total' => 100.00, 'base_row_total_incl_tax' => 119.00],
        ], true, 1.084);

        self::assertSame(129.0, $result['items'][1]['discount_amount']);
        self::assertSame(119.0, $result['items'][1]['base_discount_amount']);
        self::assertSame(19.0, $result['items'][1]['base_expected_discount_tax_compensation']);
        self::assertSame(119.0, $result['base_discount_amount']);
    }

    public function testDerivedBaseAmountsAreDistributedByLargestRemainderPerRate(): void
    {
        $rows = [
            ['id' => 'a', 'qty' => 1, 'row_total' => 1.00, 'row_total_incl_tax' => 1.00, 'tax_percent' => 0],
            ['id' => 'b', 'qty' => 1, 'row_total' => 1.00, 'row_total_incl_tax' => 1.00, 'tax_percent' => 0],
            ['id' => 'c', 'qty' => 1, 'row_total' => 1.00, 'row_total_incl_tax' => 1.00, 'tax_percent' => 0],
        ];
        $result = $this->calculator->calculate($rows, false, 3.0);

        $base = array_column($result['items'], 'base_discount_amount');
        self::assertSame([0.34, 0.33, 0.33], $base);
        self::assertSame(1.0, $result['base_discount_amount']);
        self::assertSame(1.0, $result['rates']['0']['base_discount']);
        self::assertSame(3.0, $result['discount_amount']);
    }

    public function testDerivedBaseKeepsNetPlusTaxEqualGrossPerRate(): void
    {
        $result = $this->calculator->calculate($this->rows(), true, 1.37);

        foreach ($result['rates'] as $rate) {
            self::assertSame($rate['base_gross'], round($rate['base_net'] + $rate['base_tax'], 2));
            self::assertSame(round($rate['gross'] / 1.37, 2), $rate['base_gross']);
            self::assertSame(round($rate['net'] / 1.37, 2), $rate['base_net']);
        }
        $itemSum = round(array_sum(array_column($result['items'], 'base_discount_amount')), 2);
        self::assertSame($result['base_discount_amount'], $itemSum);
    }

    public function testEmptyRowsGiveZero(): void
    {
        $result = $this->calculator->calculate([], true);
        self::assertSame([], $result['items']);
        self::assertSame([], $result['rates']);
        self::assertSame(0.0, $result['discount_amount']);
        self::assertSame(0.0, $result['base_discount_amount']);
    }

    public function testZeroDecimalCurrency(): void
    {
        $calculator = new ReproductionDiscountCalculator(0);
        $result = $calculator->calculate([
            ['id' => 1, 'qty' => 2, 'row_total' => 1818.2, 'row_total_incl_tax' => 2000, 'tax_percent' => 10],
        ], true);
        self::assertSame(2000.0, $result['items'][1]['discount_amount']);
        self::assertSame(182.0, $result['items'][1]['expected_discount_tax_compensation']);
    }

    /**
     * @dataProvider invalidRows
     */
    public function testInvalidInputIsRejected(array $rows, float $rate = 1.0): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->calculate($rows, true, $rate);
    }

    public static function invalidRows(): array
    {
        $ok = ['id' => 1, 'qty' => 1, 'row_total' => 1, 'row_total_incl_tax' => 1.19, 'tax_percent' => 19];
        return [
            'qty zero' => [[['qty' => 0] + $ok]],
            'negative amount' => [[['row_total' => -1] + $ok]],
            'gross below net' => [[['row_total_incl_tax' => 0.5] + $ok]],
            'non numeric' => [[['row_total' => 'abc'] + $ok]],
            'missing amount' => [[array_diff_key($ok, ['row_total_incl_tax' => true])]],
            'negative tax percent' => [[['tax_percent' => -1] + $ok]],
            'duplicate id' => [[$ok, $ok]],
            'zero currency rate' => [[$ok], 0.0],
        ];
    }

    public function testInvalidDecimalsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ReproductionDiscountCalculator(5);
    }
}
