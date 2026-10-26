<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Test\Unit\Model\Production;

use PHPUnit\Framework\TestCase;
use Printess\PrintessEditor\Model\Production\DropshipCallbackParser;

class DropshipCallbackParserTest extends TestCase
{
    private DropshipCallbackParser $parser;

    protected function setUp(): void
    {
        $this->parser = new DropshipCallbackParser();
    }

    public function testParsesLineItemsAndIgnoresPrintessIds(): void
    {
        $entries = $this->parser->parse([
            'orderId'     => 999,
            'orderStatus' => 'shipped',
            'lineItems'   => [
                ['lineItemId' => 1, 'nonce' => ' n1 ', 'trackingId' => ' T1 ', 'trackingUrl' => ' https://t/1 '],
                ['lineItemId' => 2, 'nonce' => 'n2', 'trackingId' => 12345],
            ],
        ]);

        self::assertSame([
            ['nonce' => 'n1', 'tracking_id' => 'T1', 'tracking_url' => 'https://t/1'],
            ['nonce' => 'n2', 'tracking_id' => '12345', 'tracking_url' => ''],
        ], $entries);
    }

    public function testSkipsEntriesWithoutUsableNonce(): void
    {
        $entries = $this->parser->parse(['lineItems' => [
            ['nonce' => '', 'trackingId' => 'T'],
            ['nonce' => '   '],
            ['trackingId' => 'T'],
            ['nonce' => ['x']],
            'garbage',
            ['nonce' => 'ok'],
        ]]);

        self::assertSame([['nonce' => 'ok', 'tracking_id' => '', 'tracking_url' => '']], $entries);
    }

    public function testMissingOrInvalidLineItems(): void
    {
        self::assertSame([], $this->parser->parse([]));
        self::assertSame([], $this->parser->parse(['lineItems' => 'x']));
        self::assertSame([], $this->parser->parse(['lineItems' => []]));
        self::assertSame([], $this->parser->parse(['nonce' => 'top-level', 'trackingId' => 'T']));
    }

    public function testMergeAddsNewShipment(): void
    {
        $result = $this->parser->mergeTracking([], ['trackingId' => 'T1', 'trackingUrl' => 'u1', 'shippedAt' => '2026-10-12T10:00:00+00:00'], 1);

        self::assertTrue($result['added']);
        self::assertSame([[
            'trackingId'  => 'T1',
            'trackingUrl' => 'u1',
            'shippedAt'   => '2026-10-12T10:00:00+00:00',
            'send'        => 1,
        ]], $result['list']);
    }

    public function testMergeDefaultsShippedAtToNowIso8601(): void
    {
        $result = $this->parser->mergeTracking([], ['tracking_id' => 'T1', 'tracking_url' => null], 0);
        $entry  = $result['list'][0];

        self::assertSame('T1', $entry['trackingId']);
        self::assertSame('', $entry['trackingUrl']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, $entry['shippedAt']));
    }

    public function testMergeDeduplicatesOnIdUrlAndSend(): void
    {
        $existing = [['trackingId' => 'T1', 'trackingUrl' => 'u1', 'shippedAt' => 'x', 'send' => 1]];

        $same = $this->parser->mergeTracking($existing, ['trackingId' => ' T1 ', 'trackingUrl' => 'u1'], 1);
        self::assertFalse($same['added']);
        self::assertSame($existing, $same['list']);

        $otherUrl = $this->parser->mergeTracking($existing, ['trackingId' => 'T1', 'trackingUrl' => 'u2'], 1);
        self::assertTrue($otherUrl['added']);
        self::assertCount(2, $otherUrl['list']);
        self::assertSame(1, $otherUrl['list'][1]['send']);

        $otherId = $this->parser->mergeTracking($existing, ['trackingId' => 'T2', 'trackingUrl' => 'u1'], 1);
        self::assertTrue($otherId['added']);
    }

    public function testMergeSameTrackingOfLaterSendIsNewShipment(): void
    {
        $existing = [['trackingId' => 'T1', 'trackingUrl' => 'u1', 'shippedAt' => 'x', 'send' => 1]];

        $resent = $this->parser->mergeTracking($existing, ['trackingId' => 'T1', 'trackingUrl' => 'u1'], 2);
        self::assertTrue($resent['added']);
        self::assertCount(2, $resent['list']);
        self::assertSame(2, $resent['list'][1]['send']);

        // ... and a callback retry of that second send is a repeat again.
        $retry = $this->parser->mergeTracking($resent['list'], ['trackingId' => 'T1', 'trackingUrl' => 'u1'], 2);
        self::assertFalse($retry['added']);
        self::assertCount(2, $retry['list']);
    }

    public function testMergeEmptyTrackingIsDeduplicatedWithinSendOnly(): void
    {
        $first  = $this->parser->mergeTracking([], [], 1);
        $second = $this->parser->mergeTracking($first['list'], ['trackingId' => '', 'trackingUrl' => ''], 1);
        $resent = $this->parser->mergeTracking($second['list'], ['trackingId' => '', 'trackingUrl' => ''], 2);

        self::assertTrue($first['added']);
        self::assertFalse($second['added']);
        self::assertCount(1, $second['list']);
        self::assertTrue($resent['added']);
        self::assertCount(2, $resent['list']);
    }

    public function testMergeDropsMalformedExistingEntries(): void
    {
        // An entry without (numeric) send counts as send 0.
        $existing = ['junk', ['trackingId' => 'A', 'trackingUrl' => ''], ['trackingId' => 'B', 'send' => 'x']];

        $result = $this->parser->mergeTracking($existing, ['trackingId' => 'A'], 0);
        self::assertFalse($result['added']);
        self::assertSame([['trackingId' => 'A', 'trackingUrl' => ''], ['trackingId' => 'B', 'send' => 'x']], $result['list']);

        self::assertFalse($this->parser->mergeTracking($existing, ['trackingId' => 'B'], 0)['added']);
        self::assertTrue($this->parser->mergeTracking($existing, ['trackingId' => 'A'], 1)['added']);
    }

    public function testMergeNegativeSendIsClampedToZero(): void
    {
        $result = $this->parser->mergeTracking([['trackingId' => 'A', 'trackingUrl' => '', 'send' => 0]], ['trackingId' => 'A'], -3);
        self::assertFalse($result['added']);
    }

    public function testDecodeTracking(): void
    {
        self::assertSame([], $this->parser->decodeTracking(null));
        self::assertSame([], $this->parser->decodeTracking(''));
        self::assertSame([], $this->parser->decodeTracking('{broken'));
        self::assertSame([], $this->parser->decodeTracking('"str"'));
        self::assertSame(
            [['trackingId' => 'A']],
            $this->parser->decodeTracking('[{"trackingId":"A"}, 5]')
        );
    }
}
