<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Production;

/**
 * PURE. Builds the request bodies for /production/produce (print jobs) and /dropship/produce.
 *
 * $job is the PdfJob data array (save_token, copies, print_settings, nonce, product_definition_id,
 * linked_order_id, ...). $ctx keys: origin, order_increment_id, order_id, order_item_id, store_id,
 * callback_base_url ('' = poll), shipping_code, shipping_title, customer_name, dropship_data_id,
 * linked_count, shop_order_id (fallback order_increment_id), linked_order_id (fallback job linked_order_id).
 * `dispatch_notice` is not read here (it travels in the dropship address record).
 */
class ProduceRequestBuilder
{
    public const RENDER_CALLBACK_PATH   = 'printess/production/callback';
    public const DROPSHIP_CALLBACK_PATH = 'printess/dropship/callback';
    public const NONCE_HEADER           = 'X-Printess-Nonce';

    /** Mirrors PdfJob::DROPSHIP_CALLBACK_TYPE_* (kept local: this class is PURE, PdfJob is a Magento model). */
    public const CALLBACK_TYPE_NONE   = 0;
    public const CALLBACK_TYPE_NONCES = 3;

    public function buildPrint(array $job, array $ctx): array
    {
        $body = $this->buildCommon($job, $ctx);

        // Baseline print request (D1): the order ENTITY id as externalOrderId, published version, 300 dpi —
        // always, also when a print settings template is sent alongside.
        $orderId = (int) ($ctx['order_id'] ?? 0);
        $body['externalOrderId']     = $orderId > 0 ? (string) $orderId : $body['externalOrderId'];
        $body['usePublishedVersion'] = true;
        $body['outputSettings']      = ['dpi' => 300];

        return $body;
    }

    /** Dropship callbackType for a configured Production Callback URL: 3 (URL + one nonce per item) or 0 (poll). */
    public static function callbackTypeFor(string $callbackBaseUrl): int
    {
        return trim($callbackBaseUrl) !== ''
            ? self::CALLBACK_TYPE_NONCES
            : self::CALLBACK_TYPE_NONE;
    }

    public function buildDropship(array $job, array $ctx): array
    {
        $body         = $this->buildCommon($job, $ctx);
        $callbackUrl  = $this->str($ctx['callback_base_url'] ?? '');
        $callbackType = self::callbackTypeFor($callbackUrl);
        $nonce        = $this->str($job['nonce'] ?? '');

        $linkedId = $this->str($ctx['linked_order_id'] ?? '');
        if ($linkedId === '') {
            $linkedId = $this->str($job['linked_order_id'] ?? '');
        }

        $dropship = [
            'dropshipDataId'         => (int) ($ctx['dropship_data_id'] ?? 0),
            // 0 is meaningful ("take it from the Printess template"), so it must be sent as-is.
            'productDefinitionId'    => (int) ($job['product_definition_id'] ?? 0),
            'callbackType'           => $callbackType,
            'linkedOrderLineItems'   => max(1, (int) ($ctx['linked_count'] ?? 1)),
            'linkedOrderLineItemsId' => $linkedId,
        ];

        if ($callbackType === self::CALLBACK_TYPE_NONCES) {
            // Type 3: Printess posts to the URL and echoes each shipped item's nonce. The order is in the URL,
            // the item is found by its nonce; no long-lived secret and no Printess ids are needed.
            $dropship['callbackPayload'] = [
                'url'   => $callbackUrl . self::DROPSHIP_CALLBACK_PATH . '?order=' . (int) ($ctx['order_id'] ?? 0),
                'nonce' => $nonce,
            ];
        }

        $shippingCode  = $this->str($ctx['shipping_code'] ?? '');
        $shippingTitle = $this->str($ctx['shipping_title'] ?? '');
        if ($shippingCode !== '') {
            $dropship['shippingCode'] = $shippingCode;
        }
        if ($shippingTitle !== '') {
            $dropship['shippingTitle'] = $shippingTitle;
        }

        $dropship['data'] = [
            'shopOrderId'  => $this->str($ctx['shop_order_id'] ?? '') ?: $this->str($ctx['order_increment_id'] ?? ''),
            'customerName' => $this->str($ctx['customer_name'] ?? ''),
        ];

        $body['dropship'] = $dropship;

        return $body;
    }

    private function buildCommon(array $job, array $ctx): array
    {
        $nonce = $this->str($job['nonce'] ?? '');

        $body = [
            'templateName'    => $this->str($job['save_token'] ?? ''),
            'origin'          => $this->str($ctx['origin'] ?? '') ?: 'Magento',
            // Dropship: the increment id; buildPrint overrides it with the entity id (baseline behaviour).
            'externalOrderId' => $this->str($ctx['order_increment_id'] ?? ''),
            'copies'          => max(1, (int) ($job['copies'] ?? 1)),
            // Always carries the nonce so every Printess order is traceable to the Magento job, even when polling.
            'orderMetaData'   => (string) json_encode([
                'magentoOrderId'   => (int) ($ctx['order_id'] ?? 0),
                'orderIncrementId' => $this->str($ctx['order_increment_id'] ?? ''),
                'orderItemId'      => (int) ($ctx['order_item_id'] ?? 0),
                'storeId'          => (int) ($ctx['store_id'] ?? 0),
                'nonce'            => $nonce,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ];

        $printSettings = $this->str($job['print_settings'] ?? '');
        if ($printSettings !== '') {
            $body['printSettingsTemplate'] = $printSettings;
        }

        $callbackBase = $this->str($ctx['callback_base_url'] ?? '');
        if ($callbackBase !== '') {
            $body['callbackUrl']     = $callbackBase . self::RENDER_CALLBACK_PATH;
            $body['callbackHeaders'] = [self::NONCE_HEADER => $nonce];
        }

        return $body;
    }

    private function str(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
