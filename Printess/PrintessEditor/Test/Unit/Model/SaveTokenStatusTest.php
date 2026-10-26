<?php
declare(strict_types=1);

namespace Printess\PrintessEditor\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Printess\PrintessEditor\Model\SaveTokenStatus;

final class SaveTokenStatusTest extends TestCase
{
    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-13T12:00:00Z');
    }

    public function testNotFoundIsInactive(): void
    {
        self::assertSame(['state' => SaveTokenStatus::INACTIVE, 'expires_on' => null], SaveTokenStatus::fromInfo(null, $this->now()));
    }

    public function testNullExpiryNeverExpires(): void
    {
        self::assertSame(SaveTokenStatus::ACTIVE, SaveTokenStatus::fromInfo(['expiresOn' => null], $this->now())['state']);
    }

    public function testDeletedMarkerIsInactive(): void
    {
        $r = SaveTokenStatus::fromInfo(['expiresOn' => '1999-01-01T00:00:00Z'], $this->now());
        self::assertSame(SaveTokenStatus::INACTIVE, $r['state']);
    }

    public function testPastAndNowAreInactive(): void
    {
        self::assertSame(SaveTokenStatus::INACTIVE, SaveTokenStatus::fromInfo(['expiresOn' => '2026-10-01T00:00:00Z'], $this->now())['state']);
        self::assertSame(SaveTokenStatus::INACTIVE, SaveTokenStatus::fromInfo(['expiresOn' => '2026-10-13T12:00:00Z'], $this->now())['state']);
    }

    public function testFutureIsActiveAndNormalisedToUtc(): void
    {
        $r = SaveTokenStatus::fromInfo(['expiresOn' => '2026-11-12T14:00:00+02:00', 'createdOn' => '2026-10-13T00:00:00Z'], $this->now());
        self::assertSame(['state' => SaveTokenStatus::ACTIVE, 'expires_on' => '2026-11-12T12:00:00Z'], $r);
    }

    public function testMissingOrGarbageExpiryIsUnknown(): void
    {
        self::assertSame(SaveTokenStatus::UNKNOWN, SaveTokenStatus::fromInfo(['createdOn' => '2026-10-13T00:00:00Z'], $this->now())['state']);
        self::assertSame(SaveTokenStatus::UNKNOWN, SaveTokenStatus::fromInfo(['expiresOn' => 'not a date'], $this->now())['state']);
        self::assertSame(SaveTokenStatus::UNKNOWN, SaveTokenStatus::fromInfo(['expiresOn' => 42], $this->now())['state']);
    }
}
