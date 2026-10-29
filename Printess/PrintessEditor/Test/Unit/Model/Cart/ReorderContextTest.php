<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Test\Unit\Model\Cart;

use PHPUnit\Framework\TestCase;
use Printess\PrintessEditor\Model\Cart\ReorderContext;
use Printess\PrintessEditor\Model\PrintessApi;

class ReorderContextTest extends TestCase
{
    private function opts(string $token): array
    {
        return [
            'printess_save_token'    => ['label' => 'Printess Save Token', 'value' => $token],
            'printess_thumbnail_url' => ['label' => 'Printess Thumbnail', 'value' => 'https://t/' . $token],
        ];
    }

    public function testInactiveByDefaultAndClaimsNothing(): void
    {
        $context = new ReorderContext();
        self::assertFalse($context->isActive());
        self::assertNull($context->claim(1, ['product' => '1']));
    }

    public function testExactBuyRequestMatchWinsOverPosition(): void
    {
        $context = new ReorderContext();
        $context->start(1, 7, [
            ['key' => 'a', 'product_id' => 5, 'buy_request' => ['product' => '5', 'saveToken' => 'st:a', 'qty' => 1], 'options' => $this->opts('st:a')],
            ['key' => 'b', 'product_id' => 5, 'buy_request' => ['product' => '5', 'saveToken' => 'st:b', 'qty' => 1], 'options' => $this->opts('st:b')],
        ]);

        // Reorder passes qty = qty ordered (as float) and strips custom_price; ints vs strings must not matter.
        $b = $context->claim(5, ['saveToken' => 'st:b', 'product' => 5, 'qty' => 3.0, 'add_to_cart_invalid_product' => false]);
        self::assertSame('b', $b['key']);
        self::assertSame('st:b', $b['options']['printess_save_token']['value']);

        $a = $context->claim(5, ['product' => '5', 'saveToken' => 'st:a', 'qty' => 1]);
        self::assertSame('a', $a['key']);

        // Each source is claimed once only.
        self::assertNull($context->claim(5, ['product' => '5', 'saveToken' => 'st:a']));
    }

    public function testConfigurableSuperAttributesDistinguishSources(): void
    {
        $sources = [
            ['product_id' => 9, 'buy_request' => ReorderContext::normalizeBuyRequest(['super_attribute' => ['93' => '50', '144' => '167']]), 'claimed' => false],
            ['product_id' => 9, 'buy_request' => ReorderContext::normalizeBuyRequest(['super_attribute' => ['93' => '52', '144' => '167']]), 'claimed' => false],
        ];
        self::assertSame(1, ReorderContext::match($sources, 9, ['super_attribute' => ['144' => 167, '93' => 52], 'qty' => 2]));
        self::assertSame(0, ReorderContext::match($sources, 9, ['super_attribute' => ['93' => '50', '144' => '167']]));
    }

    public function testFallsBackToSaveTokenOnlyWhenTheRequestCarriesIt(): void
    {
        $sources = [
            ['product_id' => 3, 'buy_request' => ['saveToken' => 'st:x', 'options' => ['1' => 'a']], 'claimed' => false],
            ['product_id' => 3, 'buy_request' => ['saveToken' => 'st:y', 'options' => ['1' => 'a']], 'claimed' => false],
        ];
        // Custom option values changed shape → no exact match; the saveToken in the buy request decides.
        self::assertSame(1, ReorderContext::match($sources, 3, ['saveToken' => 'st:y', 'options' => ['1' => ['a']]]));
        // No token in the request and no exact match → nothing (no "first source of the product" fallback).
        self::assertNull(ReorderContext::match($sources, 3, ['options' => ['1' => ['a']]]));
        self::assertNull(ReorderContext::match($sources, 3, []));
        // Unknown token → nothing.
        self::assertNull(ReorderContext::match($sources, 3, ['saveToken' => 'st:z']));
        // Claimed sources are skipped even on a token match.
        $sources[1]['claimed'] = true;
        self::assertNull(ReorderContext::match($sources, 3, ['saveToken' => 'st:y']));
        // Other product → no match (non-Printess item or third-party add).
        self::assertNull(ReorderContext::match($sources, 4, ['saveToken' => 'st:x']));
    }

    public function testPlainLineOfSameProductDoesNotStealTheDesign(): void
    {
        $context = new ReorderContext();
        // Order: plain line A (no Printess design, so not a source) + Printess line B, same product 5.
        $context->start(1, 7, [
            ['key' => 'b', 'product_id' => 5, 'buy_request' => ['product' => '5', 'saveToken' => 'st:b', 'qty' => 1], 'options' => $this->opts('st:b')],
        ]);

        // Reorder adds A first: it must get nothing.
        self::assertNull($context->claim(5, ['product' => '5', 'qty' => 2.0]));
        // B still gets its design.
        $b = $context->claim(5, ['product' => '5', 'saveToken' => 'st:b', 'qty' => 1.0]);
        self::assertNotNull($b);
        self::assertSame('b', $b['key']);
        self::assertSame('st:b', $b['options']['printess_save_token']['value']);
    }

    public function testSameProductTwiceWithDifferentDesigns(): void
    {
        $context = new ReorderContext();
        $context->start(1, 7, [
            ['key' => 'x', 'product_id' => 8, 'buy_request' => ['product' => '8', 'saveToken' => 'st:x', 'printessPageCount' => 4], 'options' => $this->opts('st:x')],
            ['key' => 'y', 'product_id' => 8, 'buy_request' => ['product' => '8', 'saveToken' => 'st:y', 'printessPageCount' => 6], 'options' => $this->opts('st:y')],
        ]);

        // Added in reverse order; exact match picks the right design for each.
        $y = $context->claim(8, ['product' => 8, 'saveToken' => 'st:y', 'printessPageCount' => '6', 'qty' => 1]);
        self::assertSame('y', $y['key']);
        self::assertSame('st:y', $y['options']['printess_save_token']['value']);

        // Second line differs from its source (e.g. an extension added a key) → matched by its own token.
        $x = $context->claim(8, ['product' => 8, 'saveToken' => 'st:x', 'printessPageCount' => '4', 'extra' => '1']);
        self::assertSame('x', $x['key']);
        self::assertSame('st:x', $x['options']['printess_save_token']['value']);

        self::assertNull($context->claim(8, ['product' => 8, 'saveToken' => 'st:x']));
    }

    public function testExtractPrintessOptions(): void
    {
        self::assertSame([], ReorderContext::extractPrintessOptions(null));
        self::assertSame([], ReorderContext::extractPrintessOptions(['foo' => ['value' => 'x']]));
        self::assertSame([], ReorderContext::extractPrintessOptions(['printess_save_token' => ['value' => '  ']]));

        $all = $this->opts('st:1') + ['gift_note' => ['label' => 'Note', 'value' => 'hi']];
        self::assertSame($this->opts('st:1'), ReorderContext::extractPrintessOptions($all));
    }

    public function testStartStopErrorsAndTokenCache(): void
    {
        $context = new ReorderContext();
        $context->start(2, 11, []);
        self::assertTrue($context->isActive());
        self::assertSame(2, $context->getStoreId());
        self::assertSame(11, $context->getCustomerId());

        $context->addError('expired');
        $context->addError('expired');
        self::assertSame(['expired'], $context->getErrors());

        self::assertNull($context->getTokenStatus('st:1'));
        $context->setTokenStatus('st:1', PrintessApi::TOKEN_STATE_INACTIVE);
        self::assertSame(PrintessApi::TOKEN_STATE_INACTIVE, $context->getTokenStatus('st:1'));
        $context->setTokenStatus('st:2', PrintessApi::TOKEN_STATE_UNKNOWN);
        self::assertSame(PrintessApi::TOKEN_STATE_UNKNOWN, $context->getTokenStatus('st:2'));

        try {
            $context->start(1, 0, []);
            self::fail('Nested start must throw');
        } catch (\LogicException) {
        }

        $context->stop();
        self::assertFalse($context->isActive());
        self::assertSame([], $context->getErrors());
        self::assertNull($context->getTokenStatus('st:1'));
        self::assertSame(0, $context->getCustomerId());
    }
}
