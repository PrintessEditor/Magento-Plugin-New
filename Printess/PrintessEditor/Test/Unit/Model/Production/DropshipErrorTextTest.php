<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Test\Unit\Model\Production;

use PHPUnit\Framework\TestCase;
use Printess\PrintessEditor\Model\Production\DropshipOrderSync;

final class DropshipErrorTextTest extends TestCase
{
    public function testDotNetStackTraceIsRemoved(): void
    {
        $raw = 'System.InvalidOperationException: {"message":"Failed OneFlow Structure Validation"} '
            . 'at Printess.BackendLibrary.OneFlow.SiteflowClient.SendOrder(OrderDto dto) in /x/SiteflowClient.cs:line 233 '
            . 'at Printess.Retry.Run()';
        self::assertSame(
            'System.InvalidOperationException: {"message":"Failed OneFlow Structure Validation"}',
            DropshipOrderSync::readableError($raw)
        );
    }

    public function testPlainMessagesStayAndLongOnesAreCut(): void
    {
        self::assertSame('Address is invalid at the moment (retry later)', DropshipOrderSync::readableError('Address is invalid at the moment (retry later)'));
        $long = DropshipOrderSync::readableError(str_repeat('x', 3000), 100);
        self::assertSame(100, mb_strlen($long));
        self::assertStringEndsWith('…', $long);
    }
}
