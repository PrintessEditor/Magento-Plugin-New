<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Test\Unit\Model\Cart;

use PHPUnit\Framework\TestCase;
use Printess\PrintessEditor\Model\Cart\PagePricing;

class PagePricingTest extends TestCase
{
    public function testDecodeAcceptsPostedStringsAndArrays(): void
    {
        $p = new PagePricing();
        self::assertSame([24, 20, ['cover' => 'hard']], $p->decodePricingParams([
            'printessPageCount' => '24', 'printessIncludedPages' => '20', 'printessFormFields' => '{"cover":"hard"}',
        ]));
        self::assertSame([0, 0, []], $p->decodePricingParams(['printessPageCount' => '-3', 'printessFormFields' => 'nope']));
        self::assertSame([1, 0, ['a' => 1]], $p->decodePricingParams(['printessPageCount' => 1, 'printessFormFields' => ['a' => 1]]));
    }

    public function testBillablePages(): void
    {
        $p = new PagePricing();
        self::assertSame(4, $p->billablePages(24, 20));
        self::assertSame(0, $p->billablePages(20, 24));
        self::assertSame(10, $p->billablePages(10, 0));
    }

    public function testMostSpecificRuleWins(): void
    {
        $p = new PagePricing();
        $rules = $p->normalizeRules('[{"conditions":"","pricePerPage":"1.5"},'
            . '{"conditions":"cover=Hard","pricePerPage":"2"},'
            . '{"conditions":"cover=hard,paper=matt","pricePerPage":"3"}]');

        self::assertSame(1.5, $p->resolvePricePerPage($rules, ['cover' => 'soft']));
        self::assertSame(2.0, $p->resolvePricePerPage($rules, ['cover' => 'HARD', 'paper' => 'gloss']));
        self::assertSame(3.0, $p->resolvePricePerPage($rules, ['cover' => 'hard', 'paper' => 'Matt']));
        self::assertSame(0.0, $p->resolvePricePerPage([['conditions' => 'x=1', 'pricePerPage' => 9]], []));
        self::assertSame([], $p->normalizeRules(null));
    }
}
