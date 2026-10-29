<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Test\Unit\Model\Production;

use PHPUnit\Framework\TestCase;
use Printess\PrintessEditor\Model\Production\TrackingHistory;

class TrackingHistoryTest extends TestCase
{
    public function testRowsNewestFirstWithOrderNumbers(): void
    {
        $tracking = [
            ['trackingId' => 'A1', 'trackingUrl' => 'https://t.example/A1', 'shippedAt' => '2026-10-01T10:00:00+00:00', 'send' => 1],
            ['trackingId' => 'B2', 'trackingUrl' => '', 'shippedAt' => '2026-10-05T10:00:00+00:00', 'send' => 2],
            ['trackingId' => 'C3', 'trackingUrl' => 'https://t.example/C3', 'shippedAt' => '2026-10-09T10:00:00+00:00', 'send' => 3],
        ];
        $rows = TrackingHistory::rows($tracking, [], '000000123', 3, '000000123-R2');

        self::assertSame(['C3', 'B2', 'A1'], array_column($rows, 'tracking_id'));
        self::assertSame([3, 2, 1], array_column($rows, 'send'));
        self::assertSame(['000000123-R2', '000000123-R1', '000000123'], array_column($rows, 'shop_order_id'));
        self::assertSame('https://t.example/C3', $rows[0]['tracking_url']);
        self::assertSame('2026-10-01T10:00:00+00:00', $rows[2]['shipped_at']);
    }

    public function testRecordedHistoryWinsOverComputedNumber(): void
    {
        $history = [
            ['at' => 'x', 'status' => 'submitting', 'send' => 1, 'shop_order_id' => 'LEGACY-1'],
            ['at' => 'y', 'event' => 'shipped', 'send' => 1],
            ['at' => 'z', 'status' => 'submitting', 'send' => 2, 'shop_order_id' => '000000123-R1'],
        ];
        self::assertSame([1 => 'LEGACY-1', 2 => '000000123-R1'], TrackingHistory::recordedShopOrderIds($history));

        $rows = TrackingHistory::rows([['trackingId' => 'A', 'send' => 1]], $history, '000000123', 2, '000000123-R1');
        self::assertSame('LEGACY-1', $rows[0]['shop_order_id']);
    }

    public function testCurrentSendUsesStoredShopOrderId(): void
    {
        self::assertSame('CUSTOM', TrackingHistory::shopOrderIdForSend(2, '100', 2, 'CUSTOM'));
        self::assertSame('100-R1', TrackingHistory::shopOrderIdForSend(2, '100', 2, ''));
        self::assertSame('100', TrackingHistory::shopOrderIdForSend(1, '100', 2, '100-R1'));
    }

    public function testEntryWithoutSendHasNoOrderNumber(): void
    {
        $rows = TrackingHistory::rows([['trackingId' => 'X'], 'junk'], [], '100', 1, '100');
        self::assertCount(1, $rows);
        self::assertSame(0, $rows[0]['send']);
        self::assertSame('', $rows[0]['shop_order_id']);
    }

    public function testUnsafeUrlIsDropped(): void
    {
        $rows = TrackingHistory::rows([['trackingId' => 'X', 'trackingUrl' => 'javascript:alert(1)', 'send' => 1]], [], '1', 1, '1');
        self::assertSame('', $rows[0]['tracking_url']);
        self::assertSame('http://a.b/c', TrackingHistory::safeUrl('http://a.b/c'));
    }

    public function testDecodeList(): void
    {
        self::assertSame([], TrackingHistory::decodeList(null));
        self::assertSame([], TrackingHistory::decodeList('{"a":1}'));
        self::assertSame([], TrackingHistory::decodeList('not json'));
        self::assertSame([['a' => 1]], TrackingHistory::decodeList('[{"a":1}]'));
    }
}
