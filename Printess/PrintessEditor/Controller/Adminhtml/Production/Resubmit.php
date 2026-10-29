<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Adminhtml\Production;

use Magento\Backend\App\Action\Context;
use Printess\PrintessEditor\Model\PdfJob;
use Printess\PrintessEditor\Model\PdfJobRepository;
use Printess\PrintessEditor\Model\Production\RetryService;
use Psr\Log\LoggerInterface;

/**
 * Queues a failed item without Printess order for a fresh submission (new nonce; the cron submits it).
 * Items whose previous submission outcome is unknown need `confirm_unknown=1`. Dropship items are never resubmitted
 * alone: RetryService::resubmit() sends their whole group again (resendGroup).
 */
class Resubmit extends AbstractAction
{
    public function __construct(
        Context $context,
        PdfJobRepository $pdfJobRepository,
        LoggerInterface $logger,
        private readonly RetryService $retryService
    ) {
        parent::__construct($context, $pdfJobRepository, $logger);
    }

    protected function process(PdfJob $job): void
    {
        $this->retryService->resubmit(
            (int) $job->getId(),
            (string) $this->getRequest()->getParam('confirm_unknown') === '1'
        );
        $this->messageManager->addSuccessMessage($job->isDropship()
            ? __('The dropship group of this order was sent to Printess again. See the Printess Production tab for the result.')
            : __('The item was queued for resubmission and will be sent to Printess by the next cron run.'));
    }
}
