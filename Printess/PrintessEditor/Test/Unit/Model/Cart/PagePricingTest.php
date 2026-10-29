<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Test\Unit\Model\Cart;

use PHPUnit\Framework\TestCase;
use Printess\PrintessEditor\Model\Cart\PagePricing;

class PagePricingTest extends TestCase
{
    public function testParseBookInfoListOfFields(): void
    {
        $p = new PagePricing();
        self::assertSame([24, 18, ['Paper' => 'normal', 'COVER' => 'hard']], $p->parseBookInfo([
            'inside' => ['pageCount' => 24, 'minimumSpreadCount' => 10],
            'formFieldData' => ['formFields' => [
                ['name' => 'Paper', 'value' => 'normal'], ['fieldName' => 'COVER', 'fieldValue' => 'hard'], ['value' => 'x'],
            ]],
        ]));
    }

    public function testParseBookInfoMapAndCaps(): void
    {
        $p = new PagePricing();
        // Included pages never exceed the page count; a map of fields is accepted; junk is ignored.
        self::assertSame([6, 6, ['a' => '1']], $p->parseBookInfo([
            'inside' => ['pageCount' => 6, 'minimumSpreadCount' => 10],
            'formFieldData' => ['formFields' => ['a' => 1, 'b' => ['x']]],
        ]));
        self::assertSame([0, 0, []], $p->parseBookInfo([]));
    }

    public function testHasRulesDecidesWhatIsABookProduct(): void
    {
        $p = new PagePricing();
        self::assertTrue($p->hasRules('[{"conditions":"","pricePerPage":"0.5"}]'));
        self::assertTrue($p->hasRules([['conditions' => 'Paper=cheap', 'pricePerPage' => 0.01]]));
        self::assertFalse($p->hasRules(null));
        self::assertFalse($p->hasRules(''));
        self::assertFalse($p->hasRules('[]'));
        self::assertFalse($p->hasRules([]));
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
        // Condition keys match form fields like cart edit does (FormFieldValues normalisation).
        self::assertSame(2.0, $p->resolvePricePerPage($rules, ['COVER' => 'Hard']));
        self::assertSame(3.0, $p->resolvePricePerPage($rules, ['Cover' => 'hard', 'PAPER' => 'matt']));
    }
}
