<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Block\Adminhtml\Production;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Printess\PrintessEditor\Controller\Adminhtml\Production\Batch as BatchController;
use Printess\PrintessEditor\Model\PdfJob;
use Printess\PrintessEditor\Model\Production\RetryService;

/**
 * The Approve / Restart page of a dropship group (printess/production/batch). The controller has already checked
 * that the action is allowed; this block only prepares view data.
 *
 * Extension point for PMD-21 (copy of the order): a child block named `printess.production.batch.extra` is rendered
 * inside the page (see the template); getReproductionMode() tells whether "ask" mode is active.
 */
class Batch extends Template
{
    /** @var string */
    protected $_template = 'Printess_PrintessEditor::production/batch.phtml';

    private ?array $batch = null;

    public function __construct(
        Context $context,
        private readonly RetryService $retryService,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getJobId(): int
    {
        return (int) $this->getRequest()->getParam('job_id');
    }

    public function getBatchAction(): string
    {
        return (string) $this->getRequest()->getParam('action') === BatchController::ACTION_APPROVE
            ? BatchController::ACTION_APPROVE
            : BatchController::ACTION_RESTART;
    }

    public function isApprove(): bool
    {
        return $this->getBatchAction() === BatchController::ACTION_APPROVE;
    }

    /**
     * @return array{dropship: bool, items: list<array>, next_shop_order_id: ?string, reproduction_mode: string,
     *     order_id: int, order_increment_id: string}
     */
    public function getBatch(): array
    {
        if ($this->batch === null) {
            $this->batch = $this->retryService->describeBatch($this->getJobId());
        }
        return $this->batch;
    }

    public function getReproductionMode(): string
    {
        return (string) $this->getBatch()['reproduction_mode'];
    }

    public function getItemCount(): int
    {
        return count($this->getBatch()['items']);
    }

    public function hasOutcomeUnknown(): bool
    {
        foreach ($this->getBatch()['items'] as $item) {
            if (!empty($item['outcome_unknown'])) {
                return true;
            }
        }
        return false;
    }

    public function getTitleText(): string
    {
        return (string) ($this->isApprove() ? __('Approve designs') : __('Restart production'));
    }

    /** Shopware productionBatch.approveText / restartText. */
    public function getIntroText(): string
    {
        $count = $this->getItemCount();
        if ($this->isApprove()) {
            return (string) ($count === 1
                ? __('This item is dropshipped. Please check its design before it is sent to production.')
                : __('These %1 items go to the same address and are dropshipped together as one shipment, so they are '
                    . 'approved and sent to production together. Please check all designs.', $count));
        }
        return (string) ($count === 1
            ? __('This item is dropshipped. Restarting sends it to production again as a new dropship order, so it is '
                . 'shipped again.')
            : __('These %1 items go to the same address and are dropshipped together as one shipment. Restarting sends '
                . 'all of them to production again as a new dropship order, so all of them are shipped again.', $count));
    }

    public function getButtonLabel(): string
    {
        $count = $this->getItemCount();
        if ($this->isApprove()) {
            return (string) ($count === 1
                ? __('Approve and send to production')
                : __('Approve all %1 and send to production', $count));
        }
        return (string) ($count === 1 ? __('Restart production') : __('Restart all %1', $count));
    }

    public function getStatusLabel(string $status): string
    {
        return (string) match ($status) {
            PdfJob::STATUS_AWAITING_PAYMENT  => __('Awaiting payment'),
            PdfJob::STATUS_AWAITING_APPROVAL => __('Awaiting approval'),
            PdfJob::STATUS_PENDING           => __('Queued'),
            PdfJob::STATUS_SUBMITTING        => __('Submitting'),
            PdfJob::STATUS_PRODUCING         => __('Rendering'),
            PdfJob::STATUS_RENDERED          => __('Rendered, handed to dropshipper'),
            PdfJob::STATUS_SHIPPED           => __('Shipped'),
            PdfJob::STATUS_COMPLETE          => __('Complete'),
            PdfJob::STATUS_FAILED            => __('Failed'),
            default                          => $status,
        };
    }

    public function getSendUrl(): string
    {
        return $this->getUrl('printess/production/sendbatch');
    }

    public function getBackUrl(): string
    {
        $orderId = (int) $this->getBatch()['order_id'];
        return $orderId > 0
            ? $this->getUrl('sales/order/view', ['order_id' => $orderId])
            : $this->getUrl('sales/order/index');
    }
}
