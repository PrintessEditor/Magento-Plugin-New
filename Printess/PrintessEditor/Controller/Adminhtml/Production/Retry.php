<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Adminhtml\Production;

use Magento\Backend\App\Action\Context;
use Printess\PrintessEditor\Model\PdfJob;
use Printess\PrintessEditor\Model\PdfJobRepository;
use Printess\PrintessEditor\Model\Production\RetryService;
use Psr\Log\LoggerInterface;

/**
 * /order/retry for an item that has a Printess order. Param `mode`: default | clear_dropship | reload_payment.
 */
class Retry extends AbstractAction
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
        $mode = (string) ($this->getRequest()->getParam('mode') ?: RetryService::MODE_DEFAULT);
        $resend = $this->retryService->isPastRetryWindow($job);
        $this->retryService->retry((int) $job->getId(), $mode);

        if ($resend) {
            $this->messageManager->addSuccessMessage(__(
                'The Printess order is older than %1 days, so the item is sent again from its saved design as a new '
                . 'Printess order. The next cron run submits it.',
                RetryService::RETRY_MAX_AGE_DAYS
            ));
            return;
        }
        $this->messageManager->addSuccessMessage($mode === RetryService::MODE_CLEAR_DROPSHIP
            ? __('The item is being re-rendered without dropshipping. The files will be linked in the Printess Production tab.')
            : __('The Printess order was retried. The item is being produced again.'));
    }
}
