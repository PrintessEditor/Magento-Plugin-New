<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Test\Unit\Model\Production;

use PHPUnit\Framework\TestCase;
use Printess\PrintessEditor\Model\Production\ReproductionContext;

class ReproductionContextTest extends TestCase
{
    public function testInactiveByDefault(): void
    {
        $context = new ReproductionContext();
        self::assertFalse($context->isActive());
        self::assertFalse($context->isActiveFor(5));
        self::assertFalse($context->isDiscountEnabled());
        self::assertNull($context->getOriginalOrderId());
        self::assertSame([], $context->getPendingOrderData());
    }

    public function testStartAndStop(): void
    {
        $context = new ReproductionContext();
        $context->start(42, '  Sorry!  ', ' Flat Rate - Fixed ', ['a' => 1]);

        self::assertTrue($context->isActive());
        self::assertSame(42, $context->getOriginalOrderId());
        self::assertSame('Sorry!', $context->getMessage());
        self::assertSame('Flat Rate - Fixed', $context->getShippingDescription());
        self::assertTrue($context->isDiscountEnabled());
        self::assertSame(['a' => 1], $context->getPendingOrderData());

        $context->setDiscountEnabled(false);
        $context->setPendingOrderData(['b' => 2]);
        self::assertFalse($context->isDiscountEnabled());
        self::assertSame(['b' => 2], $context->getPendingOrderData());

        $context->stop();
        self::assertFalse($context->isActive());
        self::assertSame('', $context->getMessage());
        self::assertSame('', $context->getShippingDescription());
        self::assertSame([], $context->getPendingOrderData());

        // Restartable, and the discount is enabled again.
        $context->start(7, 'x');
        self::assertTrue($context->isDiscountEnabled());
    }

    public function testIsActiveForMatchesOnlyTheOriginalOrder(): void
    {
        $context = new ReproductionContext();
        $context->start(42, 'm');

        self::assertTrue($context->isActiveFor(42));
        self::assertTrue($context->isActiveFor('42'));
        self::assertFalse($context->isActiveFor(41));
        self::assertFalse($context->isActiveFor(null));
        self::assertFalse($context->isActiveFor(''));
        self::assertFalse($context->isActiveFor('42abc'));
        self::assertFalse($context->isActiveFor(42.0));
    }

    public function testNestedStartIsRejected(): void
    {
        $context = new ReproductionContext();
        $context->start(1, 'm');
        $this->expectException(\LogicException::class);
        $context->start(2, 'm');
    }

    public function testInvalidOrderIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ReproductionContext())->start(0, 'm');
    }
}
