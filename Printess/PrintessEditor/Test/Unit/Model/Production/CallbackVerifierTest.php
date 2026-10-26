<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Test\Unit\Model\Production;

use PHPUnit\Framework\TestCase;
use Printess\PrintessEditor\Model\Production\CallbackVerifier;

class CallbackVerifierTest extends TestCase
{
    private CallbackVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new CallbackVerifier();
    }

    private static function dropshipJob(array $overrides = []): array
    {
        return array_merge([
            'production_type'   => 'dropship',
            'printess_job_id'   => 'job-1',
            'order_id'          => '77',
            'order_item_id'     => '501',
            'printess_order_id' => 'po-9',
        ], $overrides);
    }

    // ---- render ------------------------------------------------------------------------------------------------

    public function testRenderMatchingJobId(): void
    {
        self::assertTrue($this->verifier->verifyRender(self::dropshipJob(), ['jobId' => 'job-1']));
        self::assertTrue($this->verifier->verifyRender(['production_type' => 'print', 'printess_job_id' => 'job-1'], ['jobId' => 'job-1']));
    }

    public function testRenderMismatchingJobId(): void
    {
        self::assertFalse($this->verifier->verifyRender(self::dropshipJob(), ['jobId' => 'job-2']));
    }

    public function testRenderMissingJobIds(): void
    {
        self::assertFalse($this->verifier->verifyRender(self::dropshipJob(), []));
        self::assertFalse($this->verifier->verifyRender(self::dropshipJob(['printess_job_id' => null]), ['jobId' => '']));
        self::assertFalse($this->verifier->verifyRender(self::dropshipJob(['printess_job_id' => '']), ['jobId' => '']));
        self::assertFalse($this->verifier->verifyRender(self::dropshipJob(), ['jobId' => ['job-1']]));
    }

    public function testRenderNullJob(): void
    {
        self::assertFalse($this->verifier->verifyRender(null, ['jobId' => 'job-1']));
    }

    // ---- shipped -----------------------------------------------------------------------------------------------

    public function testShippedMatchesWithIntIdsInPayload(): void
    {
        self::assertTrue($this->verifier->verifyShipped(self::dropshipJob(), ['orderId' => 77, 'lineItemId' => 501]));
    }

    public function testShippedMatchesWithStringIdsInPayload(): void
    {
        self::assertTrue($this->verifier->verifyShipped(self::dropshipJob(), ['orderId' => '77', 'lineItemId' => '501']));
    }

    public function testShippedMatchesWithIntIdsInJob(): void
    {
        $job = self::dropshipJob(['order_id' => 77, 'order_item_id' => 501]);
        self::assertTrue($this->verifier->verifyShipped($job, ['orderId' => '77', 'lineItemId' => 501]));
    }

    public function testShippedOrderIdMismatch(): void
    {
        self::assertFalse($this->verifier->verifyShipped(self::dropshipJob(), ['orderId' => 78, 'lineItemId' => 501]));
    }

    public function testShippedLineItemIdMismatch(): void
    {
        self::assertFalse($this->verifier->verifyShipped(self::dropshipJob(), ['orderId' => 77, 'lineItemId' => 502]));
    }

    public function testShippedMissingIds(): void
    {
        self::assertFalse($this->verifier->verifyShipped(self::dropshipJob(), []));
        self::assertFalse($this->verifier->verifyShipped(self::dropshipJob(), ['orderId' => 77]));
        self::assertFalse($this->verifier->verifyShipped(self::dropshipJob(['order_id' => null]), ['orderId' => '', 'lineItemId' => 501]));
    }

    public function testShippedPrintessOrderIdMismatch(): void
    {
        self::assertFalse($this->verifier->verifyShipped(
            self::dropshipJob(),
            ['orderId' => 77, 'lineItemId' => 501, 'printessOrderId' => 'po-other']
        ));
    }

    public function testShippedPrintessOrderIdMatchOrMissingOnEitherSide(): void
    {
        $payload = ['orderId' => 77, 'lineItemId' => 501];
        self::assertTrue($this->verifier->verifyShipped(self::dropshipJob(), $payload + ['printessOrderId' => 'po-9']));
        self::assertTrue($this->verifier->verifyShipped(self::dropshipJob(), $payload), 'payload without printessOrderId');
        self::assertTrue(
            $this->verifier->verifyShipped(self::dropshipJob(['printess_order_id' => null]), $payload + ['printessOrderId' => 'po-x']),
            'job has no printess_order_id yet (fast callback)'
        );
    }

    public function testShippedNonDropshipJob(): void
    {
        self::assertFalse($this->verifier->verifyShipped(
            self::dropshipJob(['production_type' => 'print']),
            ['orderId' => 77, 'lineItemId' => 501]
        ));
        $noType = self::dropshipJob();
        unset($noType['production_type']);
        self::assertFalse($this->verifier->verifyShipped($noType, ['orderId' => 77, 'lineItemId' => 501]));
    }

    public function testShippedNullJob(): void
    {
        self::assertFalse($this->verifier->verifyShipped(null, ['orderId' => 77, 'lineItemId' => 501]));
    }

    public function testShippedArrayValuesNeverMatch(): void
    {
        self::assertFalse($this->verifier->verifyShipped(self::dropshipJob(), ['orderId' => [77], 'lineItemId' => 501]));
    }
}
