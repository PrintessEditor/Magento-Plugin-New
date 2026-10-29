<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Test\Unit\Model\Production;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Printess\PrintessEditor\Model\Production\OrderStage;
use Printess\PrintessEditor\Model\Production\OrderStageCalculator;

class OrderStageCalculatorTest extends TestCase
{
    private OrderStageCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new OrderStageCalculator();
    }

    private static function ds(string $status, ?int $state = null): array
    {
        return ['production_type' => 'dropship', 'status' => $status, 'dropship_state' => $state];
    }

    private static function print(string $status): array
    {
        return ['production_type' => 'print', 'status' => $status, 'dropship_state' => null];
    }

    public function testNoJobsIsNull(): void
    {
        self::assertNull($this->calculator->calculate([]));
    }

    public function testPrintJobsAreIgnored(): void
    {
        self::assertNull($this->calculator->calculate([self::print('failed'), self::print('producing')]));
        self::assertSame(
            OrderStage::SUBMITTED,
            $this->calculator->calculate([self::print('failed'), self::print('pending'), self::ds('rendered')]),
            'A failed or pending print job must not influence the order stage.'
        );
    }

    public function testJobsWithoutProductionTypeAreIgnored(): void
    {
        self::assertNull($this->calculator->calculate([['status' => 'failed']]));
    }

    public function testFailedBeatsEverything(): void
    {
        self::assertSame(OrderStage::ERROR, $this->calculator->calculate([
            self::ds('shipped'),
            self::ds('rendered', 11),
            self::ds('pending'),
            self::ds('failed'),
        ]));
        self::assertSame(OrderStage::ERROR, $this->calculator->calculate([self::ds('shipped'), self::ds('failed')]));
    }

    public function testPartialShipment(): void
    {
        self::assertSame(OrderStage::PARTIALLY_SHIPPED, $this->calculator->calculate([self::ds('shipped'), self::ds('pending')]));
        self::assertSame(OrderStage::PARTIALLY_SHIPPED, $this->calculator->calculate([self::ds('rendered', 10), self::ds('shipped')]));
    }

    public function testAllShippedIsNull(): void
    {
        self::assertNull($this->calculator->calculate([self::ds('shipped'), self::ds('shipped'), self::print('complete')]));
    }

    /**
     * @dataProvider singleJobStages
     */
    #[DataProvider('singleJobStages')]
    public function testSingleUnshippedJob(string $status, ?int $state, ?string $expected): void
    {
        self::assertSame($expected, $this->calculator->calculate([self::ds($status, $state)]));
    }

    public static function singleJobStages(): array
    {
        return [
            'awaiting_payment'     => ['awaiting_payment', null, null],
            'awaiting_approval'    => ['awaiting_approval', null, null],
            'pending'              => ['pending', null, null],
            'unknown status'       => ['weird', null, null],
            'submitting'           => ['submitting', null, OrderStage::RENDERING],
            'producing'            => ['producing', null, OrderStage::RENDERING],
            'rendered, no state'   => ['rendered', null, OrderStage::SUBMITTED],
            'rendered, state 0'    => ['rendered', 0, OrderStage::SUBMITTED],
            'rendered, state 10'   => ['rendered', 10, OrderStage::IN_PRODUCTION],
            'rendered, state 11'   => ['rendered', 11, OrderStage::IN_PRODUCTION],
            'rendered, state 1 (partial combined shipment)' => ['rendered', 1, OrderStage::IN_PRODUCTION],
            'rendered, state 99'   => ['rendered', 99, OrderStage::SUBMITTED],
        ];
    }

    public function testDropshipStateAsNumericString(): void
    {
        self::assertSame(OrderStage::IN_PRODUCTION, $this->calculator->calculate([
            ['production_type' => 'dropship', 'status' => 'rendered', 'dropship_state' => '10'],
        ]));
    }

    public function testLeastAdvancedJobDecides(): void
    {
        self::assertSame(OrderStage::SUBMITTED, $this->calculator->calculate([self::ds('rendered', 11), self::ds('rendered')]));
        self::assertSame(OrderStage::RENDERING, $this->calculator->calculate([self::ds('rendered', 11), self::ds('producing')]));
        self::assertNull($this->calculator->calculate([self::ds('rendered', 11), self::ds('submitting'), self::ds('pending')]));
        self::assertSame(OrderStage::IN_PRODUCTION, $this->calculator->calculate([self::ds('rendered', 10), self::ds('rendered', 11)]));
    }

    public function testOrderStageConstants(): void
    {
        self::assertSame('printess_rendering', OrderStage::RENDERING);
        self::assertSame('printess_dropship_submitted', OrderStage::SUBMITTED);
        self::assertSame('printess_dropship_in_production', OrderStage::IN_PRODUCTION);
        self::assertSame('printess_dropship_part_shipped', OrderStage::PARTIALLY_SHIPPED);
        self::assertSame('printess_dropship_error', OrderStage::ERROR);
        self::assertSame(
            [OrderStage::RENDERING, OrderStage::SUBMITTED, OrderStage::IN_PRODUCTION, OrderStage::PARTIALLY_SHIPPED, OrderStage::ERROR],
            OrderStage::ALL
        );
        self::assertSame(OrderStage::ALL, array_keys(OrderStage::LABELS));
        self::assertSame('Printess: Sent to dropshipper', OrderStage::LABELS[OrderStage::SUBMITTED]);
        // sales_order_status.status is varchar(32): a longer code is stored cut off and can never be set.
        foreach (OrderStage::ALL as $status) {
            self::assertLessThanOrEqual(32, strlen($status), $status . ' is too long for sales_order_status.status');
        }
        // Every status has a customer-facing storefront label, and none of them mentions an error.
        self::assertSame(OrderStage::ALL, array_keys(OrderStage::STOREFRONT_LABELS));
        foreach (OrderStage::STOREFRONT_LABELS as $label) {
            self::assertStringNotContainsStringIgnoringCase('error', $label);
            self::assertStringNotContainsString('Printess', $label);
        }
        self::assertSame(['processing'], OrderStage::STATE_ASSIGNMENTS[OrderStage::PARTIALLY_SHIPPED]);
        foreach ([OrderStage::RENDERING, OrderStage::SUBMITTED, OrderStage::IN_PRODUCTION, OrderStage::ERROR] as $stage) {
            self::assertSame(['new', 'processing'], OrderStage::STATE_ASSIGNMENTS[$stage], $stage);
        }
    }
}
