<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Adminhtml\Production;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Result\PageFactory;
use Printess\PrintessEditor\Model\PdfJob;
use Printess\PrintessEditor\Model\PdfJobRepository;
use Printess\PrintessEditor\Model\Production\RetryService;
use Psr\Log\LoggerInterface;

/**
 * GET printess/production/batch?job_id=<id>&action=approve|restart
 *
 * The Approve / Restart page of a dropship group (plan §1.4): lists the items that are sent together and the order
 * number the dropshipper receives, and posts to printess/production/sendbatch. A plain admin page instead of a modal,
 * so it never nests a form inside the order view's tab container.
 */
class Batch extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Printess_PrintessEditor::production';

    public const ACTION_APPROVE = 'approve';
    public const ACTION_RESTART = 'restart';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly PdfJobRepository $pdfJobRepository,
        private readonly RetryService $retryService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $orderId = 0;
        try {
            $jobId = (int) $this->getRequest()->getParam('job_id');
            if ($jobId <= 0) {
                throw new LocalizedException(__('Missing Printess job id.'));
            }
            $job = $this->pdfJobRepository->getById($jobId);
            $orderId = (int) $job->getData('order_id');
            $action = (string) $this->getRequest()->getParam('action');
            $this->assertAllowed($job, $action);

            $page = $this->pageFactory->create();
            $page->setActiveMenu('Magento_Sales::sales_order');
            $page->getConfig()->getTitle()->prepend($action === self::ACTION_APPROVE
                ? __('Approve designs')
                : __('Restart production'));
            return $page;
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Printess: could not open the production batch page', [
                'error' => $e->getMessage(), 'exception' => $e,
            ]);
            $this->messageManager->addErrorMessage(__('The Printess action failed: %1', $e->getMessage()));
        }

        $redirect = $this->resultRedirectFactory->create();
        return $orderId > 0
            ? $redirect->setPath('sales/order/view', ['order_id' => $orderId])
            : $redirect->setPath('sales/order/index');
    }

    /**
     * Shared with SendBatch, so the page and the action can never disagree.
     *
     * @throws LocalizedException
     */
    public static function assertActionAllowed(PdfJob $job, string $action, RetryService $retryService): void
    {
        if ($action === self::ACTION_APPROVE) {
            if ((string) $job->getData('status') !== PdfJob::STATUS_AWAITING_APPROVAL) {
                throw new LocalizedException(__('This item is not awaiting approval.'));
            }
            return;
        }
        if ($action === self::ACTION_RESTART) {
            $reason = $retryService->getResendBlockReason($job);
            if ($reason !== null) {
                throw new LocalizedException(__($reason));
            }
            return;
        }
        throw new LocalizedException(__('Unknown Printess production action "%1".', $action));
    }

    /**
     * @throws LocalizedException
     */
    private function assertAllowed(PdfJob $job, string $action): void
    {
        self::assertActionAllowed($job, $action, $this->retryService);
    }
}
