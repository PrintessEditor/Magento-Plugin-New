<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Test\Unit\Model\Production;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Printess\PrintessEditor\Model\Production\InitialStatusPolicy;

/**
 * D1: production starts immediately unless "hold until payment" is on and the order is unpaid; manual approval
 * comes after the payment gate.
 */
class InitialStatusPolicyTest extends TestCase
{
    /**
     * @dataProvider matrix
     */
    #[DataProvider('matrix')]
    public function testDecide(bool $hold, bool $paid, bool $approve, string $expected): void
    {
        self::assertSame($expected, (new InitialStatusPolicy())->decide($hold, $paid, $approve));
    }

    public static function matrix(): array
    {
        return [
            'no hold, unpaid, no approval'  => [false, false, false, 'pending'],
            'no hold, unpaid, approval'     => [false, false, true, 'awaiting_approval'],
            'no hold, paid, no approval'    => [false, true, false, 'pending'],
            'no hold, paid, approval'       => [false, true, true, 'awaiting_approval'],
            'hold, unpaid, no approval'     => [true, false, false, 'awaiting_payment'],
            'hold, unpaid, approval'        => [true, false, true, 'awaiting_payment'],
            'hold, paid, no approval'       => [true, true, false, 'pending'],
            'hold, paid, approval'          => [true, true, true, 'awaiting_approval'],
        ];
    }

    /**
     * A reproduction (order copy) starts pending whatever the payment and approval settings say.
     *
     * @dataProvider matrix
     */
    #[DataProvider('matrix')]
    public function testReproductionAlwaysStartsPending(bool $hold, bool $paid, bool $approve, string $expected): void
    {
        self::assertSame('pending', (new InitialStatusPolicy())->decide($hold, $paid, $approve, true));
    }

    /**
     * @dataProvider matrix
     */
    #[DataProvider('matrix')]
    public function testExplicitNonReproductionMatchesDefault(bool $hold, bool $paid, bool $approve, string $expected): void
    {
        self::assertSame($expected, (new InitialStatusPolicy())->decide($hold, $paid, $approve, false));
    }

    public function testStatusConstantsMatchPdfJobContract(): void
    {
        self::assertSame('awaiting_payment', InitialStatusPolicy::STATUS_AWAITING_PAYMENT);
        self::assertSame('awaiting_approval', InitialStatusPolicy::STATUS_AWAITING_APPROVAL);
        self::assertSame('pending', InitialStatusPolicy::STATUS_PENDING);
    }
}
