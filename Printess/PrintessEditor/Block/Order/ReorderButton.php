<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Block\Order;

use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\View\Element\Template;
use Magento\Sales\Model\Order\Item;

/**
 * Renders an "Edit & Reorder" action for Printess order items on the order view page.
 *
 * Bound in place of the shared Magento_Sales "additional.product.info" block (see
 * view/frontend/layout/sales_order_view.xml) — that block is set per-row via setItem()
 * immediately before toHtml() by Magento_Sales::order/items/renderer/default.phtml, so
 * getItem() here always reflects the row currently being rendered.
 */
class ReorderButton extends Template
{
    public function __construct(
        Template\Context $context,
        private readonly FormKey $formKey,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    private function getOrderItem(): ?Item
    {
        $item = $this->getItem();
        return $item instanceof Item ? $item : null;
    }

    private function getPrintessOptions(): array
    {
        $item = $this->getOrderItem();
        if (!$item) {
            return [];
        }

        try {
            $options = $item->getProductOptionByCode('additional_options');
        } catch (\Throwable) {
            return [];
        }

        return is_array($options) ? $options : [];
    }

    public function isPrintessItem(): bool
    {
        return (string) ($this->getPrintessOptions()['printess_save_token']['value'] ?? '') !== '';
    }

    public function getItemData(): array
    {
        $item = $this->getOrderItem();

        return [
            'orderItemId' => $item ? (int) $item->getItemId() : 0,
            'checkUrl'    => $this->getUrl('printess/order/reopen'),
            'formKey'     => $this->formKey->getFormKey(),
        ];
    }
}
