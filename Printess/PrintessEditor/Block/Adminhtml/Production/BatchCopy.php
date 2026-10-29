<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Block\Adminhtml\Production;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item as OrderItem;
use Printess\PrintessEditor\Controller\Adminhtml\Production\Reproduce;
use Printess\PrintessEditor\Helper\Config;
use Printess\PrintessEditor\Model\PdfJobRepository;
use Printess\PrintessEditor\Model\Production\OrderReproductionService;

/**
 * Child `printess.production.batch.extra` of the Restart page (PMD-21, plan §1.4): in "Ask each time" mode the admin
 * chooses between sending the dropship group again under the suffixed order number and duplicating the order.
 * The copy fields live in the page's form; the copy button posts to printess/production/reproduce (formaction).
 * Renders nothing unless the parent is the Restart page of a dropship group in "ask" mode.
 */
class BatchCopy extends Template
{
    /** @var string */
    protected $_template = 'Printess_PrintessEditor::production/batch-copy.phtml';

    private ?array $items = null;

    public function __construct(
        Context $context,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly PdfJobRepository $pdfJobRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getBatchBlock(): ?Batch
    {
        $parent = $this->getParentBlock();
        return $parent instanceof Batch ? $parent : null;
    }

    public function isEnabled(): bool
    {
        $batch = $this->getBatchBlock();
        if ($batch === null || $batch->isApprove()) {
            return false;
        }
        try {
            return $batch->getBatch()['dropship']
                && $batch->getReproductionMode() === Config::REPRODUCTION_MODE_ASK
                && $this->getItems() !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    public function getChoiceCopy(): string
    {
        return Reproduce::CHOICE_COPY;
    }

    public function getChoiceResend(): string
    {
        return Reproduce::CHOICE_RESEND;
    }

    public function getResendLabel(): string
    {
        $number = $this->getBatchBlock()?->getBatch()['next_shop_order_id'] ?? null;
        $number = $number !== null && $number !== ''
            ? $number
            : (string) ($this->getBatchBlock()?->getBatch()['order_increment_id'] ?? '');
        return (string) __('Send again under the order number %1', $number);
    }

    public function getDefaultMessage(): string
    {
        return (string) __(OrderReproductionService::DEFAULT_MESSAGE);
    }

    public function getReproduceUrl(): string
    {
        return $this->getUrl('printess/production/reproduce');
    }

    /**
     * All Printess items of the order (any item with a save token), the restarted dropship group preselected.
     *
     * @return list<array{order_item_id: int, name: string, sku: string, qty: int, dropship: bool, selected: bool}>
     */
    public function getItems(): array
    {
        if ($this->items !== null) {
            return $this->items;
        }
        $this->items = [];

        $batch = $this->getBatchBlock()?->getBatch();
        $orderId = (int) ($batch['order_id'] ?? 0);
        if ($orderId <= 0) {
            return $this->items;
        }
        try {
            $order = $this->orderRepository->get($orderId);
        } catch (\Throwable) {
            return $this->items;
        }
        if (!$order instanceof Order) {
            return $this->items;
        }

        $selected = [];
        foreach ($batch['items'] ?? [] as $item) {
            $selected[(int) $item['order_item_id']] = true;
        }
        $dropship = [];
        foreach ($this->pdfJobRepository->getByOrderId($orderId) as $job) {
            if ($job->isDropship()) {
                $dropship[(int) $job->getData('order_item_id')] = true;
            }
        }

        foreach ($order->getAllVisibleItems() as $item) {
            if (!$item instanceof OrderItem || !$this->isPrintessItem($item)) {
                continue;
            }
            $itemId = (int) $item->getId();
            $this->items[] = [
                'order_item_id' => $itemId,
                'name'          => (string) $item->getName(),
                'sku'           => (string) $item->getSku(),
                'qty'           => max(1, (int) $item->getQtyOrdered()),
                'dropship'      => isset($dropship[$itemId]),
                'selected'      => isset($selected[$itemId]),
            ];
        }
        return $this->items;
    }

    private function isPrintessItem(OrderItem $item): bool
    {
        $options = $item->getProductOptions();
        $token = is_array($options) ? ($options['additional_options']['printess_save_token']['value'] ?? null) : null;
        return is_scalar($token) && (string) $token !== '';
    }

    protected function _toHtml()
    {
        return $this->isEnabled() ? parent::_toHtml() : '';
    }
}
