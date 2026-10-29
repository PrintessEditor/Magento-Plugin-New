<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Test\Unit\Model\Production;

use PHPUnit\Framework\TestCase;
use Printess\PrintessEditor\Model\Production\DesignAvailabilityCheck;
use Printess\PrintessEditor\Model\SaveTokenStatus;

class DesignAvailabilityCheckTest extends TestCase
{
    private const DESIGNS = [
        ['label' => 'P1', 'token' => 'st:a'],
        ['label' => 'P2', 'token' => 'st:b'],
        ['label' => 'P3', 'token' => 'st:c'],
    ];

    public function testAllActiveOrUnknownIsNotRefused(): void
    {
        self::assertNull(DesignAvailabilityCheck::refusalMessage(
            self::DESIGNS,
            [SaveTokenStatus::ACTIVE, SaveTokenStatus::UNKNOWN, SaveTokenStatus::ACTIVE],
            'restarted'
        ));
    }

    public function testInactiveDesignsAreNamed(): void
    {
        $message = DesignAvailabilityCheck::refusalMessage(
            self::DESIGNS,
            [SaveTokenStatus::INACTIVE, SaveTokenStatus::ACTIVE, SaveTokenStatus::INACTIVE],
            'copied'
        );
        self::assertNotNull($message);
        self::assertStringStartsWith('Nothing was copied: the design of item P1, P3 no longer exists', $message);
        self::assertStringNotContainsString('P2', $message);
    }

    public function testMissingStateCountsAsNotInactive(): void
    {
        self::assertNull(DesignAvailabilityCheck::refusalMessage(self::DESIGNS, [], 'restarted'));
    }
}
