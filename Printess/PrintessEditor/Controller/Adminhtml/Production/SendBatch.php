<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Adminhtml\Production;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Exception\LocalizedException;
use Printess\PrintessEditor\Model\PdfJob;
use Printess\PrintessEditor\Model\PdfJobRepository;
use Printess\PrintessEditor\Model\Production\JobReleaseService;
use Printess\PrintessEditor\Model\Production\RetryService;
use Psr\Log\LoggerInterface;

/**
 * POST from the batch page (printess/production/batch). Param `batch_action` (or `action`):
 * - approve: approves the group and sends it to Printess right away (JobReleaseService::approveJob);
 * - restart: sends the dropship group again as a new dropship order (RetryService::resendGroup).
 * Redirects back to the order view with the outcome.
 */
class SendBatch extends AbstractAction
{
    public function __construct(
        Context $context,
        PdfJobRepository $pdfJobRepository,
        LoggerInterface $logger,
        private readonly JobReleaseService $jobReleaseService,
        private readonly RetryService $retryService
    ) {
        parent::__construct($context, $pdfJobRepository, $logger);
    }

    protected function process(PdfJob $job): void
    {
        // `batch_action`: an input named "action" would shadow form.action in the browser.
        $action = (string) ($this->getRequest()->getParam('batch_action') ?: $this->getRequest()->getParam('action'));
        Batch::assertActionAllowed($job, $action, $this->retryService);
        // "Ask each time" mode (PMD-21): the copy posts to printess/production/reproduce. A resend submitted while
        // the copy option is selected (e.g. without JavaScript) is refused instead of guessing the admin's intent.
        if ((string) $this->getRequest()->getParam('reproduction_choice') === Reproduce::CHOICE_COPY) {
            throw new LocalizedException(__(
                'You chose to duplicate the order. Use "Duplicate order and send copy", or choose to send again.'
            ));
        }

        if ($action === Batch::ACTION_APPROVE) {
            $this->jobReleaseService->approveJob((int) $job->getId());
        } else {
            $this->retryService->resendGroup((int) $job->getId());
        }

        if (!$job->isDropship()) {
            $this->messageManager->addSuccessMessage(
                __('The Printess item was approved and will be submitted by the next cron run.')
            );
            return;
        }

        $this->reportDropshipOutcome((int) $job->getId());
    }

    private function reportDropshipOutcome(int $jobId): void
    {
        $job = $this->pdfJobRepository->getById($jobId);
        $status = (string) $job->getData('status');

        if ($status === PdfJob::STATUS_PENDING) {
            $this->messageManager->addNoticeMessage(__(
                'The dropship items are queued and will be sent to Printess by the next cron run.'
            ));
            return;
        }

        if ($status === PdfJob::STATUS_FAILED) {
            $this->messageManager->addErrorMessage(__(
                'The dropship group could not be sent: %1',
                (string) $job->getData('error_message')
            ));
            return;
        }

        $linkedId = (string) $job->getData('linked_order_id');
        $sent = 0;
        $failed = 0;
        $total = 0;
        foreach ($this->pdfJobRepository->getByOrderId((int) $job->getData('order_id')) as $member) {
            if (!$member->isDropship() || $linkedId === '' || (string) $member->getData('linked_order_id') !== $linkedId) {
                continue;
            }
            $total++;
            if ((string) $member->getData('status') === PdfJob::STATUS_FAILED) {
                $failed++;
            } elseif ((string) $member->getData('status') !== PdfJob::STATUS_SUBMITTING) {
                $sent++;
            }
        }

        if ($failed === 0 && $sent === $total && $total > 0) {
            $this->messageManager->addSuccessMessage(__(
                '%1 dropship item(s) were sent to production as dropship order %2.',
                $total,
                (string) $job->getData('dropship_shop_order_id')
            ));
            return;
        }

        $this->messageManager->addErrorMessage(__(
            'The dropship group was not sent completely (%1 of %2 item(s) accepted by Printess). See the Printess '
            . 'Production tab for the errors.',
            $sent,
            max($total, 1)
        ));
    }
}
