<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Block\Order;

use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

/**
 * "Reproduction of order #…" on the customer's order view (sales_order_view) and the guest order view
 * (sales_guest_view), PMD-21 / plan §1.5 step 7.
 *
 * The original is linked only on the customer's order view and only when it belongs to the same customer account as
 * the viewed order (Magento's own order view controller has already checked that the viewer owns the viewed order).
 * On the guest view (layout argument `guest_view`) and for guest orders only the number is shown.
 * Reads the JSON stored in sales_order.printess_reproduction_of.
 */
class ReproductionInfo extends Template
{
    /** @var string */
    protected $_template = 'Printess_PrintessEditor::order/reproduction-info.phtml';

    private ?array $info = null;

    public function __construct(
        Template\Context $context,
        private readonly Registry $registry,
        private readonly OrderRepositoryInterface $orderRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array{increment_id: string, url: string}|null url '' when the original is not linkable
     */
    public function getReproductionOf(): ?array
    {
        if ($this->info !== null) {
            return $this->info === [] ? null : $this->info;
        }
        $this->info = [];

        $order = $this->registry->registry('current_order');
        if (!$order instanceof Order) {
            return null;
        }
        $json = $order->getData('printess_reproduction_of');
        $data = is_string($json) && trim($json) !== '' ? json_decode($json, true) : null;
        if (!is_array($data) || array_is_list($data)) {
            return null;
        }

        $originalId = isset($data['id']) && is_numeric($data['id']) ? (int) $data['id'] : 0;
        $incrementId = isset($data['increment_id']) && is_scalar($data['increment_id'])
            ? trim((string) $data['increment_id'])
            : '';

        $url = '';
        $customerId = (int) $order->getCustomerId();
        if ($originalId > 0 && $customerId > 0 && !$this->getData('guest_view')) {
            try {
                $original = $this->orderRepository->get($originalId);
                if ((int) $original->getCustomerId() === $customerId) {
                    $url = $this->getUrl('sales/order/view', ['order_id' => $originalId]);
                    $incrementId = $incrementId !== '' ? $incrementId : (string) $original->getIncrementId();
                }
            } catch (\Throwable) {
                $url = '';
            }
        }
        if ($incrementId === '') {
            return null;
        }

        $this->info = ['increment_id' => $incrementId, 'url' => $url];
        return $this->info;
    }

    protected function _toHtml()
    {
        return $this->getReproductionOf() !== null ? parent::_toHtml() : '';
    }
}
