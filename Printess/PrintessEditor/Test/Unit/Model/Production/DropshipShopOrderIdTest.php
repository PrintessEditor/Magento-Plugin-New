<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Test\Unit\Model\Production;

use PHPUnit\Framework\TestCase;
use Printess\PrintessEditor\Model\Production\DropshipShopOrderId;

class DropshipShopOrderIdTest extends TestCase
{
    public function testFirstSendUsesIncrementId(): void
    {
        self::assertSame('000000123', DropshipShopOrderId::build('000000123', 1));
    }

    public function testLaterSendsGetSuffix(): void
    {
        self::assertSame('000000123-R1', DropshipShopOrderId::build('000000123', 2));
        self::assertSame('000000123-R2', DropshipShopOrderId::build('000000123', 3));
        self::assertSame('000000123-R10', DropshipShopOrderId::build(' 000000123 ', 11));
    }

    public function testNonPositiveSendNumberCountsAsFirstSend(): void
    {
        self::assertSame('100', DropshipShopOrderId::build('100', 0));
        self::assertSame('100', DropshipShopOrderId::build('100', -3));
    }

    public function testNextSendNumber(): void
    {
        self::assertSame(1, DropshipShopOrderId::nextSendNumber([]));
        self::assertSame(1, DropshipShopOrderId::nextSendNumber([null, '', 0, '0']));
        self::assertSame(2, DropshipShopOrderId::nextSendNumber([1, 1]));
        // Mixed groups (e.g. an item added later) continue after the highest count.
        self::assertSame(4, DropshipShopOrderId::nextSendNumber([0, '3', 2, 'x']));
    }

    public function testSendNumberAndOrderIdTogether(): void
    {
        $next = DropshipShopOrderId::nextSendNumber([1, 1]);
        self::assertSame('000000042-R1', DropshipShopOrderId::build('000000042', $next));
    }
}
