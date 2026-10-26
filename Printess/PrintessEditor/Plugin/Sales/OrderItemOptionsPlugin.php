<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Plugin\Sales;

use Printess\PrintessEditor\Model\Sales\FriendlyOptionLabelFormatter;

/**
 * Strip Printess-internal options (save token, project id, thumbnail url,
 * base price, included pages, form fields) from customer-facing order rendering,
 * and render friendlier labels/values for the remaining Printess custom options.
 *
 * Matches by the option's "label" (e.g. "Printess Save Token") rather than an array
 * key, since other plugins on this same method may reindex the array and drop any
 * string keys we'd otherwise depend on.
 *
 * Mirrors Plugin\Helper\ProductConfigurationPlugin, which does the same for
 * the cart/minicart display via a different Magento class (that plugin is
 * never invoked by order email or order-view templates, which is why these
 * keys were leaking through unfiltered).
 */
class OrderItemOptionsPlugin
{
    /**
     * @var FriendlyOptionLabelFormatter
     */
    private $friendlyOptionLabelFormatter;

    public function __construct(FriendlyOptionLabelFormatter $friendlyOptionLabelFormatter)
    {
        $this->friendlyOptionLabelFormatter = $friendlyOptionLabelFormatter;
    }

    /**
     * Strip Printess-internal options from the customer-facing item options list.
     *
     * @param  \Magento\Sales\Block\Order\Email\Items\Order\DefaultOrder|\Magento\Sales\Block\Order\Item\Renderer\DefaultRenderer $subject
     * @param  array $result
     * @return array
     */
    public function afterGetItemOptions($subject, array $result): array
    {
        $filtered = array_values(array_filter($result, static function ($option) {
            $label = is_array($option) ? ($option['label'] ?? '') : '';
            return strpos((string)$label, 'Printess ') !== 0;
        }));

        $filtered = $this->friendlyOptionLabelFormatter->format($filtered, $this->getStoreId($subject));

        return $filtered;
    }

    /**
     * Best-effort store id lookup: not every block invoking getItemOptions()
     * (order/invoice/shipment/creditmemo email + item renderer) is guaranteed
     * to expose getOrder(), so fall back to the current store on failure.
     *
     * @param object $subject
     * @return int|string|null
     */
    private function getStoreId($subject)
    {
        try {
            $order = $subject->getOrder();
            return $order ? $order->getStoreId() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
