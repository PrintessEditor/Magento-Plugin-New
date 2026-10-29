<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Test\Unit\Model\Production;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Printess\PrintessEditor\Model\Production\DropshipOrderStateMapper as Mapper;

class DropshipOrderStateMapperTest extends TestCase
{
    private Mapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new Mapper();
    }

    public function testShippedWithTracking(): void
    {
        self::assertSame([
            'action'       => Mapper::ACTION_SHIPPED,
            'state'        => 1,
            'tracking_id'  => 'TRK1',
            'tracking_url' => 'https://track.example/TRK1',
            'error'        => null,
        ], $this->mapper->map(['state' => 1, 'trackingId' => ' TRK1 ', 'trackingPayload' => 'https://track.example/TRK1']));
    }

    public function testShippedWithoutTrackingUrl(): void
    {
        $result = $this->mapper->map(['state' => 1, 'trackingId' => 'TRK1']);
        self::assertSame(Mapper::ACTION_SHIPPED, $result['action']);
        self::assertNull($result['tracking_url']);
    }

    /**
     * @dataProvider partialTracking
     */
    #[DataProvider('partialTracking')]
    public function testShippedPartialOrNoTrackingIsProgress(array $order): void
    {
        $result = $this->mapper->map($order);
        self::assertSame(Mapper::ACTION_PROGRESS, $result['action']);
        self::assertNull($result['tracking_id']);
    }

    public static function partialTracking(): array
    {
        return [
            'partial'       => [['state' => 1, 'trackingId' => 'partial']],
            'PARTIAL'       => [['state' => 1, 'trackingId' => 'PARTIAL']],
            'empty'         => [['state' => 1, 'trackingId' => '']],
            'missing'       => [['state' => 1]],
        ];
    }

    /**
     * @dataProvider progressStates
     */
    #[DataProvider('progressStates')]
    public function testProgressStates(mixed $state, int $expectedState): void
    {
        $result = $this->mapper->map(['state' => $state]);
        self::assertSame(Mapper::ACTION_PROGRESS, $result['action']);
        self::assertSame($expectedState, $result['state']);
    }

    public static function progressStates(): array
    {
        return [
            'submitted'        => [0, 0],
            'received'         => [10, 10],
            'print ready'      => [11, 11],
            'numeric string'   => ['10', 10],
            'float'            => [11.0, 11],
        ];
    }

    public function testCancelled(): void
    {
        $result = $this->mapper->map(['state' => 98]);
        self::assertSame(Mapper::ACTION_FAILED, $result['action']);
        self::assertSame('Cancelled by the dropshipper', $result['error']);
    }

    public function testError(): void
    {
        self::assertSame('Out of paper', $this->mapper->map(['state' => 99, 'errorMessage' => ' Out of paper '])['error']);
        $result = $this->mapper->map(['state' => 99]);
        self::assertSame(Mapper::ACTION_FAILED, $result['action']);
        self::assertSame('The dropshipper reported an error', $result['error']);
    }

    public function testUnknownStateIsNone(): void
    {
        $result = $this->mapper->map(['state' => 42, 'trackingId' => 'TRK']);
        self::assertSame(Mapper::ACTION_NONE, $result['action']);
        self::assertSame(42, $result['state']);
        self::assertNull($result['tracking_id']);
    }

    /**
     * @dataProvider missingStates
     */
    #[DataProvider('missingStates')]
    public function testMissingOrInvalidStateIsNone(array $order): void
    {
        $result = $this->mapper->map($order);
        self::assertNull($result['state']);
        self::assertSame(
            Mapper::ACTION_NONE,
            $result['action'],
            'Regression guard: switch() compares loosely (null == 0), so a missing/non-numeric state must not be '
            . 'treated as STATE_SUBMITTED (progress); it is an unknown state (none).'
        );
    }

    public static function missingStates(): array
    {
        return [
            'missing'     => [[]],
            'null'        => [['state' => null]],
            'non-numeric' => [['state' => 'abc']],
            'bool'        => [['state' => true]],
        ];
    }
}
