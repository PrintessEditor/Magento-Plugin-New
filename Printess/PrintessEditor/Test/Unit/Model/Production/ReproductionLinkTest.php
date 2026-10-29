<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Test\Unit\Model\Production;

use PHPUnit\Framework\TestCase;
use Printess\PrintessEditor\Model\Production\DropshipAddressMapper;
use Printess\PrintessEditor\Model\Production\ReproductionLink;

class ReproductionLinkTest extends TestCase
{
    public function testBuildAndParseReproductionOf(): void
    {
        $json = ReproductionLink::buildReproductionOf(
            12,
            '000000012',
            'Leave at the door',
            [5 => 77, '6' => '78'],
            ReproductionLink::DISCOUNT_PATH_COLLECTOR,
            'flatrate_flatrate',
            'Flat Rate - Fixed',
            'Due to dropship issues we needed to recreate the order.'
        );
        $raw = json_decode($json, true);
        self::assertSame(['5' => 77, '6' => 78], $raw['item_map']);

        self::assertSame([
            'id'                   => 12,
            'increment_id'         => '000000012',
            'customer_note'        => 'Leave at the door',
            'item_map'             => [5 => 77, 6 => 78],
            'discount_path'        => 'collector',
            'shipping_method'      => 'flatrate_flatrate',
            'shipping_description' => 'Flat Rate - Fixed',
            'message'              => 'Due to dropship issues we needed to recreate the order.',
        ], ReproductionLink::parseReproductionOf($json));
    }

    public function testEmptyItemMapIsAnObjectAndNullNoteIsEmpty(): void
    {
        $json = ReproductionLink::buildReproductionOf(3, '3', null, [], ReproductionLink::DISCOUNT_PATH_ZERO_PRICE);
        self::assertStringContainsString('"item_map":{}', $json);
        self::assertStringContainsString('"customer_note":""', $json);
        self::assertSame('zero_price', ReproductionLink::parseReproductionOf($json)['discount_path']);
    }

    public function testDispatchNoticeReadsTheOriginalNoteFromTheLink(): void
    {
        $json = ReproductionLink::buildReproductionOf(3, '3', ' Ring twice ', [], ReproductionLink::DISCOUNT_PATH_COLLECTOR);
        self::assertSame('Ring twice', DropshipAddressMapper::dispatchNotice('merchant message', $json));
    }

    public function testUnknownDiscountPathIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReproductionLink::buildReproductionOf(3, '3', null, [], 'coupon');
    }

    public function testParseReproductionOfRejectsGarbage(): void
    {
        self::assertNull(ReproductionLink::parseReproductionOf(null));
        self::assertNull(ReproductionLink::parseReproductionOf(''));
        self::assertNull(ReproductionLink::parseReproductionOf('17'));
        self::assertNull(ReproductionLink::parseReproductionOf('{"id":0}'));
        self::assertNull(ReproductionLink::parseReproductionOf('{nope'));
        $parsed = ReproductionLink::parseReproductionOf('{"id":"9","item_map":{"x":1,"4":"y","5":6},"increment_id":[1]}');
        self::assertSame(9, $parsed['id']);
        self::assertSame('', $parsed['increment_id']);
        self::assertSame([5 => 6], $parsed['item_map']);
        self::assertSame('collector', $parsed['discount_path']);
    }

    public function testAppendReproducedAs(): void
    {
        $first = ReproductionLink::appendReproducedAs(null, 20, '000000020', [5, '6'], '2026-10-12T10:00:00+00:00');
        $second = ReproductionLink::appendReproducedAs($first, 21, '000000021', [5], '2026-10-12T11:00:00+00:00');

        self::assertSame([
            ['id' => 20, 'increment_id' => '000000020', 'item_ids' => [5, 6], 'created_at' => '2026-10-12T10:00:00+00:00'],
            ['id' => 21, 'increment_id' => '000000021', 'item_ids' => [5], 'created_at' => '2026-10-12T11:00:00+00:00'],
        ], ReproductionLink::parseReproducedAs($second));
    }

    public function testAppendReproducedAsReplacesInvalidExistingValue(): void
    {
        $json = ReproductionLink::appendReproducedAs('{"id":1}', 2, '2', [], 'now');
        self::assertCount(1, ReproductionLink::parseReproducedAs($json));
        self::assertSame([], ReproductionLink::parseReproducedAs('[{"id":0},"x",{"increment_id":"5"}]'));
        self::assertSame([], ReproductionLink::parseReproducedAs('nope'));
    }

    public function testSplitShippingDescription(): void
    {
        self::assertSame(
            ['carrier_title' => 'Flat Rate', 'method_title' => 'Fixed'],
            ReproductionLink::splitShippingDescription('Flat Rate - Fixed', 'F')
        );
        self::assertSame(
            ['carrier_title' => 'DHL', 'method_title' => 'Express - Saturday'],
            ReproductionLink::splitShippingDescription(' DHL - Express - Saturday ', 'F')
        );
        self::assertSame(
            ['carrier_title' => 'Pickup-Point', 'method_title' => ''],
            ReproductionLink::splitShippingDescription('Pickup-Point', 'F')
        );
        self::assertSame(
            ['carrier_title' => 'Reproduction (free)', 'method_title' => ''],
            ReproductionLink::splitShippingDescription('  ', 'Reproduction (free)')
        );
    }

    public function testIsZero(): void
    {
        self::assertTrue(ReproductionLink::isZero());
        self::assertTrue(ReproductionLink::isZero(0.0, -0.00009, 0.00001));
        self::assertFalse(ReproductionLink::isZero(0.0, 0.0001));
        self::assertFalse(ReproductionLink::isZero(-19.0));
        self::assertFalse(ReproductionLink::isZero(NAN));
    }

    public function testSelectItemIds(): void
    {
        $candidates = [
            10 => ['parent_id' => null, 'printess' => true],
            11 => ['parent_id' => 10, 'printess' => false],   // child of a configurable Printess item
            12 => ['parent_id' => null, 'printess' => false], // not a Printess item
            13 => ['parent_id' => null, 'printess' => true],
            14 => ['parent_id' => null, 'printess' => true],
        ];

        self::assertSame([10, 13], ReproductionLink::selectItemIds(['13', 10, 12, 99, 'x', 13], $candidates));
        self::assertSame([10], ReproductionLink::selectItemIds([11], $candidates));
        self::assertSame([], ReproductionLink::selectItemIds([12, 99], $candidates));
        self::assertSame([], ReproductionLink::selectItemIds([], $candidates));
    }
}
