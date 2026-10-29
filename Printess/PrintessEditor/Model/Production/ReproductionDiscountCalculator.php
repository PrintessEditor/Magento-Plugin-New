<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Production;

/**
 * Computes the per-item discount that makes a reproduction copy free (spike S1, Option A).
 *
 * PURE: plain arrays in, plain arrays out, no Magento dependency. The `printess_reproduction` total collector
 * (sort_order between shipping_discount 400 and tax 450) feeds it the quote items as tax_subtotal (200) left them
 * and writes the result back to discount_amount / base_discount_amount.
 *
 * Why the offset depends on "catalog prices include tax" (see S1-report.md):
 *  - prices INCL tax: the tax calculators take taxable = row_total_incl_tax - discount_amount, so the discount must be
 *    the gross row total. Tax becomes 0, the calculator books the former tax as discount_tax_compensation, and
 *    grand total = net + 0 + compensation - gross = 0.
 *  - prices EXCL tax: taxable = row_total - discount_amount, compensation stays 0, so the discount must be the net
 *    row total. A gross discount here would make the grand total negative by the tax amount.
 * Both only hold when tax is applied AFTER discount; the collector forces that for flagged quotes.
 *
 * Exact rounding: every amount is handled as integer minor units (cents). Each row's offset is its own rounded row
 * total, so the sum per tax rate equals that rate's subtotal without remainder (Shopware's per-tax-rate credit line).
 * When base amounts are not supplied, they are derived per tax rate from the store amounts and the base->store
 * currency rate, and distributed over the rows by largest remainder, so base rows also add up exactly.
 *
 * Row input keys: id (int|string), qty (float > 0), row_total, row_total_incl_tax (store currency), tax_percent,
 * optional base_row_total, base_row_total_incl_tax, price (informational only).
 */
class ReproductionDiscountCalculator
{
    private int $decimals;
    private int $factor;

    public function __construct(int $decimals = 2)
    {
        if ($decimals < 0 || $decimals > 4) {
            throw new \InvalidArgumentException('decimals must be between 0 and 4');
        }
        $this->decimals = $decimals;
        $this->factor = 10 ** $decimals;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param bool $priceIncludesTax catalog prices include tax (tax/calculation/price_includes_tax)
     * @param float $baseToStoreRate store amount = base amount * rate (quote base_to_quote_rate); 1.0 for same currency
     * @return array{
     *     items: array<int|string, array{discount_amount: float, base_discount_amount: float, discount_percent: float,
     *         expected_discount_tax_compensation: float, base_expected_discount_tax_compensation: float}>,
     *     rates: array<string, array{tax_percent: float, net: float, tax: float, gross: float, discount: float,
     *         base_net: float, base_tax: float, base_gross: float, base_discount: float}>,
     *     discount_amount: float,
     *     base_discount_amount: float
     * }
     */
    public function calculate(array $rows, bool $priceIncludesTax, float $baseToStoreRate = 1.0): array
    {
        if (!($baseToStoreRate > 0)) {
            throw new \InvalidArgumentException('baseToStoreRate must be > 0');
        }

        $normalized = [];
        $byRate = [];
        foreach ($rows as $index => $row) {
            $id = $row['id'] ?? $index;
            if (isset($normalized[$id])) {
                throw new \InvalidArgumentException(sprintf('duplicate row id %s', (string) $id));
            }
            $qty = (float) ($row['qty'] ?? 0);
            if (!($qty > 0)) {
                throw new \InvalidArgumentException(sprintf('row %s: qty must be > 0', (string) $id));
            }
            $net = $this->toUnits($row['row_total'] ?? null, (string) $id, 'row_total');
            $gross = $this->toUnits($row['row_total_incl_tax'] ?? null, (string) $id, 'row_total_incl_tax');
            if ($gross < $net) {
                throw new \InvalidArgumentException(sprintf('row %s: row_total_incl_tax < row_total', (string) $id));
            }
            $hasBase = array_key_exists('base_row_total', $row) && array_key_exists('base_row_total_incl_tax', $row);
            $baseNet = $hasBase ? $this->toUnits($row['base_row_total'], (string) $id, 'base_row_total') : null;
            $baseGross = $hasBase
                ? $this->toUnits($row['base_row_total_incl_tax'], (string) $id, 'base_row_total_incl_tax')
                : null;
            $rateKey = $this->rateKey((float) ($row['tax_percent'] ?? 0));

            $normalized[$id] = [
                'rate' => $rateKey,
                'net' => $net,
                'gross' => $gross,
                'base_net' => $baseNet,
                'base_gross' => $baseGross,
            ];
            $byRate[$rateKey][] = $id;
        }

        // Derive missing base amounts per tax rate so base rows sum exactly to the converted rate total.
        foreach ($byRate as $ids) {
            foreach (['net', 'gross'] as $field) {
                $missing = array_values(array_filter($ids, static fn ($id) => $normalized[$id]['base_' . $field] === null));
                if ($missing === []) {
                    continue;
                }
                $storeUnits = [];
                foreach ($missing as $id) {
                    $storeUnits[$id] = $normalized[$id][$field];
                }
                foreach ($this->convertDistributed($storeUnits, $baseToStoreRate) as $id => $units) {
                    $normalized[$id]['base_' . $field] = $units;
                }
            }
        }

        $items = [];
        $rates = [];
        $total = 0;
        $baseTotal = 0;
        foreach ($normalized as $id => $n) {
            $offset = $priceIncludesTax ? $n['gross'] : $n['net'];
            $baseOffset = $priceIncludesTax ? $n['base_gross'] : $n['base_net'];
            $items[$id] = [
                'discount_amount' => $this->toAmount($offset),
                'base_discount_amount' => $this->toAmount($baseOffset),
                'discount_percent' => 100.0,
                'expected_discount_tax_compensation' => $priceIncludesTax ? $this->toAmount($n['gross'] - $n['net']) : 0.0,
                'base_expected_discount_tax_compensation' => $priceIncludesTax
                    ? $this->toAmount($n['base_gross'] - $n['base_net'])
                    : 0.0,
            ];
            $total += $offset;
            $baseTotal += $baseOffset;

            $r = $n['rate'];
            $rates[$r] ??= [
                'tax_percent' => (float) $r,
                'net' => 0, 'tax' => 0, 'gross' => 0, 'discount' => 0,
                'base_net' => 0, 'base_tax' => 0, 'base_gross' => 0, 'base_discount' => 0,
            ];
            $rates[$r]['net'] += $n['net'];
            $rates[$r]['gross'] += $n['gross'];
            $rates[$r]['tax'] += $n['gross'] - $n['net'];
            $rates[$r]['discount'] += $offset;
            $rates[$r]['base_net'] += $n['base_net'];
            $rates[$r]['base_gross'] += $n['base_gross'];
            $rates[$r]['base_tax'] += $n['base_gross'] - $n['base_net'];
            $rates[$r]['base_discount'] += $baseOffset;
        }

        foreach ($rates as $r => $sums) {
            foreach ($sums as $key => $value) {
                if ($key !== 'tax_percent') {
                    $rates[$r][$key] = $this->toAmount($value);
                }
            }
        }
        ksort($rates, SORT_NUMERIC);

        return [
            'items' => $items,
            'rates' => $rates,
            'discount_amount' => $this->toAmount($total),
            'base_discount_amount' => $this->toAmount($baseTotal),
        ];
    }

    /**
     * Converts store minor units to base minor units: the rounded converted SUM is distributed over the rows by
     * largest remainder, so the base rows add up to exactly round(sum / rate).
     *
     * @param array<int|string, int> $storeUnits
     * @return array<int|string, int>
     */
    private function convertDistributed(array $storeUnits, float $baseToStoreRate): array
    {
        $target = (int) round(array_sum($storeUnits) / $baseToStoreRate);
        $result = [];
        $remainders = [];
        foreach ($storeUnits as $id => $units) {
            $exact = $units / $baseToStoreRate;
            $floor = (int) floor($exact + 1e-9);
            $result[$id] = $floor;
            $remainders[$id] = $exact - $floor;
        }
        $left = $target - array_sum($result);
        // Stable order: biggest remainder first, ties by input order.
        $order = array_keys($remainders);
        usort($order, static function ($a, $b) use ($remainders, $order) {
            $cmp = $remainders[$b] <=> $remainders[$a];
            return $cmp !== 0 ? $cmp : array_search($a, $order, true) <=> array_search($b, $order, true);
        });
        // Sum of floors <= target and the remainders sum to < count, so 0 <= $left <= count (float-epsilon aside).
        foreach ($order as $id) {
            if ($left <= 0) {
                break;
            }
            $result[$id]++;
            $left--;
        }
        foreach (array_reverse($order) as $id) {
            if ($left >= 0) {
                break;
            }
            if ($result[$id] > 0) {
                $result[$id]--;
                $left++;
            }
        }
        return $result;
    }

    private function toUnits(mixed $value, string $id, string $field): int
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            throw new \InvalidArgumentException(sprintf('row %s: %s must be numeric', $id, $field));
        }
        $float = (float) $value;
        if ($float < 0) {
            throw new \InvalidArgumentException(sprintf('row %s: %s must be >= 0', $id, $field));
        }
        // Pre-round to 6 places to drop float noise such as 119.99999999999 before rounding to minor units.
        return (int) round(round($float, 6) * $this->factor);
    }

    private function toAmount(int $units): float
    {
        return round($units / $this->factor, $this->decimals);
    }

    private function rateKey(float $percent): string
    {
        if ($percent < 0) {
            throw new \InvalidArgumentException('tax_percent must be >= 0');
        }
        return rtrim(rtrim(number_format($percent, 4, '.', ''), '0'), '.');
    }
}
