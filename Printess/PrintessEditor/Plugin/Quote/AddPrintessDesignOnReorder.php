<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Plugin\Quote;

use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Printess\PrintessEditor\Model\Cart\PrintessOptionsCopier;
use Printess\PrintessEditor\Model\Cart\ReorderContext;
use Printess\PrintessEditor\Model\PrintessApi;

/**
 * PMD-23: while a native reorder is in progress (ReorderContext active, see Plugin\Sales\KeepPrintessDesignOnReorder),
 * every Quote::addProduct call is matched to its source order item. For a Printess item:
 *  - save token definitely inactive (Printess says expired / not found) → the add is skipped (no design-less product
 *    in the cart) and an error is recorded;
 *  - token state unknown (no service token, API outage, ...) → the design is kept (logged by the copier): we never
 *    tell the customer a design expired when we don't know;
 *  - otherwise the design (additional_options) goes onto the candidate before the add and onto the resulting parent
 *    item after it, page pricing is re-applied from the buy request and the design is synced into "My Projects".
 * No-op outside a reorder.
 */
class AddPrintessDesignOnReorder
{
    /**
     * $copier is injected as PrintessOptionsCopier\Proxy (etc/di.xml): this plugin runs on every Quote::addProduct.
     */
    public function __construct(
        private readonly ReorderContext $context,
        private readonly PrintessOptionsCopier $copier
    ) {
    }

    /**
     * @return QuoteItem|string|null null when a Printess item is skipped because its design expired
     */
    public function aroundAddProduct(Quote $subject, callable $proceed, Product $product, $request = null, ...$rest)
    {
        if (!$this->context->isActive()) {
            return $proceed($product, $request, ...$rest);
        }

        $buyRequest = $request instanceof DataObject
            ? (array) $request->getData()
            : (is_numeric($request) ? ['qty' => $request] : []);
        $source = $this->context->claim((int) $product->getId(), $buyRequest);
        if ($source === null) {
            return $proceed($product, $request, ...$rest);
        }

        $options = $source['options'];
        $token = (string) ($options['printess_save_token']['value'] ?? '');
        $state = $this->context->getTokenStatus($token);
        if ($state === null) {
            $state = $this->copier->getSaveTokenState($this->context->getStoreId() ?: null, $token);
            $this->context->setTokenStatus($token, $state);
        }

        if ($state === PrintessApi::TOKEN_STATE_INACTIVE) {
            $name = (string) ($product->getName() ?: $source['name']);
            $this->context->addError((string) __(
                'The personalised design of “%1” is no longer available, so it was not added to the cart. '
                . 'Use “Edit & Reorder” on the order page to start a new design with the same settings.',
                $name
            ));
            return null;
        }

        $this->copier->prepareProduct($product, $options);
        $result = $proceed($product, $request, ...$rest);

        if ($result instanceof QuoteItem) {
            $this->copier->applyToItem($result, $options, $buyRequest);
            $this->copier->syncProject($this->context->getCustomerId(), (int) $product->getId(), $options);
        }
        return $result;
    }
}
