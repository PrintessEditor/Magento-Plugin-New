<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Test\Unit\Model\Approval;

use PHPUnit\Framework\TestCase;
use Printess\PrintessEditor\Model\Approval\SupervisorResolution;

final class SupervisorResolutionTest extends TestCase
{
    public function testFallbackChain(): void
    {
        $r = new SupervisorResolution();
        $valid = fn (int $id): bool => in_array($id, [10, 20], true);

        self::assertSame(10, $r->resolve(10, 20, $valid), 'product wins');
        self::assertSame(20, $r->resolve(null, 20, $valid), 'store when product empty');
        self::assertSame(20, $r->resolve(0, 20, $valid), 'zero = empty');
        self::assertSame(20, $r->resolve(99, 20, $valid), 'product supervisor lost the role → store');
        self::assertNull($r->resolve(99, 98, $valid), 'nobody valid → null (notify all)');
        self::assertNull($r->resolve(null, null, $valid));
    }
}
