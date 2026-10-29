<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Test\Unit\Model\Production;

use PHPUnit\Framework\TestCase;
use Printess\PrintessEditor\Model\Production\ProduceRequestBuilder;

class ProduceRequestBuilderTest extends TestCase
{
    private const NONCE = 'abc123nonce';
    private const BASE  = 'https://shop.example.com/';

    private ProduceRequestBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ProduceRequestBuilder();
    }

    private static function job(array $overrides = []): array
    {
        return array_merge([
            'save_token'            => 'st:token',
            'copies'                => 3,
            'print_settings'        => '',
            'nonce'                 => self::NONCE,
            'product_definition_id' => 42,
            'linked_order_id'       => 'linked-1',
        ], $overrides);
    }

    private static function ctx(array $overrides = []): array
    {
        return array_merge([
            'origin'             => 'Magento',
            'order_increment_id' => '000000123',
            'order_id'           => 77,
            'order_item_id'      => 501,
            'store_id'           => 2,
            'callback_base_url'  => self::BASE,
            'shipping_code'      => 'flatrate_flatrate',
            'shipping_title'     => 'Flat Rate - Fixed',
            'customer_name'      => 'Jane Doe',
            'dropship_data_id'   => 4711,
            'linked_count'       => 2,
        ], $overrides);
    }

    // ---- common -------------------------------------------------------------------------------------------------

    public function testCopiesAlwaysPresent(): void
    {
        self::assertSame(3, $this->builder->buildPrint(self::job(), self::ctx())['copies']);
        self::assertSame(3, $this->builder->buildDropship(self::job(), self::ctx())['copies']);

        $noCopies = self::job();
        unset($noCopies['copies']);
        self::assertSame(1, $this->builder->buildPrint($noCopies, self::ctx(['callback_base_url' => '']))['copies']);
        self::assertSame(1, $this->builder->buildDropship(self::job(['copies' => 0]), self::ctx())['copies']);
    }

    public function testCommonFields(): void
    {
        $body = $this->builder->buildPrint(self::job(), self::ctx(['origin' => '']));
        self::assertSame('st:token', $body['templateName']);
        self::assertSame('Magento', $body['origin'], 'empty origin defaults to Magento');
        self::assertSame('77', $body['externalOrderId'], 'print uses the order entity id (baseline)');
        self::assertSame(
            '000000123',
            $this->builder->buildDropship(self::job(), self::ctx())['externalOrderId'],
            'dropship uses the increment id'
        );
    }

    public function testOrderMetaDataIsJsonStringWithNonce(): void
    {
        foreach (['', self::BASE] as $base) {
            foreach (['buildPrint', 'buildDropship'] as $method) {
                $body = $this->builder->$method(self::job(), self::ctx(['callback_base_url' => $base]));
                self::assertIsString($body['orderMetaData'], "$method/$base");
                self::assertSame([
                    'magentoOrderId'   => 77,
                    'orderIncrementId' => '000000123',
                    'orderItemId'      => 501,
                    'storeId'          => 2,
                    'nonce'            => self::NONCE,
                ], json_decode($body['orderMetaData'], true), "$method/$base");
            }
        }
    }

    public function testRenderCallbackWhenBaseUrlSet(): void
    {
        foreach (['buildPrint', 'buildDropship'] as $method) {
            $body = $this->builder->$method(self::job(), self::ctx());
            self::assertSame('https://shop.example.com/printess/production/callback', $body['callbackUrl'], $method);
            self::assertSame(['X-Printess-Nonce' => self::NONCE], $body['callbackHeaders'], $method);
        }
    }

    public function testNoRenderCallbackWhenPolling(): void
    {
        foreach (['buildPrint', 'buildDropship'] as $method) {
            $body = $this->builder->$method(self::job(), self::ctx(['callback_base_url' => '']));
            self::assertArrayNotHasKey('callbackUrl', $body, $method);
            self::assertArrayNotHasKey('callbackHeaders', $body, $method);
        }
    }

    // ---- print --------------------------------------------------------------------------------------------------

    public function testPrintWithoutPrintSettingsUsesLegacyDefaults(): void
    {
        $body = $this->builder->buildPrint(self::job(), self::ctx());
        self::assertArrayNotHasKey('printSettingsTemplate', $body);
        self::assertTrue($body['usePublishedVersion']);
        self::assertSame(['dpi' => 300], $body['outputSettings']);
        self::assertArrayNotHasKey('dropship', $body);
    }

    public function testPrintWithPrintSettingsKeepsBaselineDefaults(): void
    {
        $body = $this->builder->buildPrint(self::job(['print_settings' => ' PDF-X ']), self::ctx());
        self::assertSame('PDF-X', $body['printSettingsTemplate']);
        self::assertTrue($body['usePublishedVersion']);
        self::assertSame(['dpi' => 300], $body['outputSettings']);
    }

    public function testPrintExternalOrderIdIsEntityIdString(): void
    {
        $body = $this->builder->buildPrint(self::job(), self::ctx(['order_id' => '1234']));
        self::assertSame('1234', $body['externalOrderId']);
    }

    // ---- dropship -----------------------------------------------------------------------------------------------

    public function testDropshipWithCallback(): void
    {
        $dropship = $this->builder->buildDropship(self::job(), self::ctx())['dropship'];

        self::assertSame(4711, $dropship['dropshipDataId']);
        self::assertSame(42, $dropship['productDefinitionId']);
        self::assertSame(3, $dropship['callbackType']);
        self::assertSame([
            'url'   => 'https://shop.example.com/printess/dropship/callback?order=77',
            'nonce' => self::NONCE,
        ], $dropship['callbackPayload']);
        self::assertSame(2, $dropship['linkedOrderLineItems']);
        self::assertSame('linked-1', $dropship['linkedOrderLineItemsId']);
        self::assertSame('flatrate_flatrate', $dropship['shippingCode']);
        self::assertSame('Flat Rate - Fixed', $dropship['shippingTitle']);
        self::assertSame(['shopOrderId' => '000000123', 'customerName' => 'Jane Doe'], $dropship['data']);
    }

    public function testDropshipWithoutCallbackPolls(): void
    {
        $body = $this->builder->buildDropship(self::job(), self::ctx(['callback_base_url' => '']));
        self::assertSame(0, $body['dropship']['callbackType']);
        self::assertArrayNotHasKey('callbackPayload', $body['dropship']);
        self::assertArrayNotHasKey('callbackUrl', $body);
    }

    public function testWhitespaceCallbackBaseUrlCountsAsPolling(): void
    {
        $body = $this->builder->buildDropship(self::job(), self::ctx(['callback_base_url' => '   ']));
        self::assertSame(0, $body['dropship']['callbackType']);
        self::assertArrayNotHasKey('callbackPayload', $body['dropship']);
        self::assertArrayNotHasKey('callbackUrl', $body);
    }

    public function testTemplateModeSendsProductDefinitionIdZero(): void
    {
        $dropship = $this->builder->buildDropship(self::job(['product_definition_id' => 0]), self::ctx())['dropship'];
        self::assertArrayHasKey('productDefinitionId', $dropship);
        self::assertSame(0, $dropship['productDefinitionId']);
    }

    public function testShippingCodeAndTitleOmittedWhenEmpty(): void
    {
        $dropship = $this->builder->buildDropship(
            self::job(),
            self::ctx(['shipping_code' => '', 'shipping_title' => '  '])
        )['dropship'];
        self::assertArrayNotHasKey('shippingCode', $dropship);
        self::assertArrayNotHasKey('shippingTitle', $dropship);

        $ctx = self::ctx();
        unset($ctx['shipping_code'], $ctx['shipping_title']);
        $dropship = $this->builder->buildDropship(self::job(), $ctx)['dropship'];
        self::assertArrayNotHasKey('shippingCode', $dropship);
        self::assertArrayNotHasKey('shippingTitle', $dropship);
    }

    public function testLinkedCountDefaultsToAtLeastOne(): void
    {
        $ctx = self::ctx();
        unset($ctx['linked_count']);
        self::assertSame(1, $this->builder->buildDropship(self::job(), $ctx)['dropship']['linkedOrderLineItems']);
        self::assertSame(1, $this->builder->buildDropship(self::job(), self::ctx(['linked_count' => 0]))['dropship']['linkedOrderLineItems']);
    }

    public function testDropshipDoesNotAddLegacyPrintDefaults(): void
    {
        $body = $this->builder->buildDropship(self::job(), self::ctx());
        self::assertArrayNotHasKey('outputSettings', $body);
        self::assertArrayNotHasKey('usePublishedVersion', $body);
    }

    public function testNonceTravelsInPayloadNotInUrl(): void
    {
        $dropship = $this->builder->buildDropship(self::job(['nonce' => 'a b&c']), self::ctx())['dropship'];
        self::assertSame('https://shop.example.com/printess/dropship/callback?order=77', $dropship['callbackPayload']['url']);
        self::assertSame('a b&c', $dropship['callbackPayload']['nonce']);
        self::assertArrayNotHasKey('orderId', $dropship['callbackPayload']);
        self::assertArrayNotHasKey('lineItemId', $dropship['callbackPayload']);
    }

    public function testShopOrderIdFromContextWithFallback(): void
    {
        $dropship = $this->builder->buildDropship(self::job(), self::ctx(['shop_order_id' => ' 000000123-R1 ']))['dropship'];
        self::assertSame('000000123-R1', $dropship['data']['shopOrderId']);

        $dropship = $this->builder->buildDropship(self::job(), self::ctx(['shop_order_id' => '']))['dropship'];
        self::assertSame('000000123', $dropship['data']['shopOrderId']);
    }

    public function testShopOrderIdDoesNotChangeExternalOrderId(): void
    {
        $body = $this->builder->buildDropship(self::job(), self::ctx(['shop_order_id' => '000000123-R2']));
        self::assertSame('000000123', $body['externalOrderId']);
    }

    public function testLinkedOrderIdFromContextWithJobFallback(): void
    {
        $dropship = $this->builder->buildDropship(self::job(), self::ctx(['linked_order_id' => 'linked-new']))['dropship'];
        self::assertSame('linked-new', $dropship['linkedOrderLineItemsId']);

        $dropship = $this->builder->buildDropship(self::job(), self::ctx(['linked_order_id' => '  ']))['dropship'];
        self::assertSame('linked-1', $dropship['linkedOrderLineItemsId']);
    }

    public function testCallbackTypeFor(): void
    {
        self::assertSame(3, ProduceRequestBuilder::callbackTypeFor(self::BASE));
        self::assertSame(0, ProduceRequestBuilder::callbackTypeFor(''));
        self::assertSame(0, ProduceRequestBuilder::callbackTypeFor('   '));
    }

    public function testPrintRequestUnaffectedByDropshipContext(): void
    {
        $plain = $this->builder->buildPrint(self::job(), self::ctx());
        $extra = $this->builder->buildPrint(
            self::job(),
            self::ctx(['shop_order_id' => 'X-R1', 'linked_order_id' => 'L2', 'dispatch_notice' => 'note'])
        );
        self::assertSame($plain, $extra);
        self::assertArrayNotHasKey('dropship', $extra);
    }
}
