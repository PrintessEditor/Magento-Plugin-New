<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Adminhtml\Production;

use Magento\Backend\App\Action\Context;
use Printess\PrintessEditor\Model\PdfJob;
use Printess\PrintessEditor\Model\PdfJobRepository;
use Printess\PrintessEditor\Model\Production\JobReleaseService;
use Psr\Log\LoggerInterface;

/**
 * awaiting_approval → pending. Print items are submitted by the next cron run; the dropship group of the order is
 * approved together and sent right away (the tab links dropship items to the batch page instead).
 */
class Approve extends AbstractAction
{
    public function __construct(
        Context $context,
        PdfJobRepository $pdfJobRepository,
        LoggerInterface $logger,
        private readonly JobReleaseService $jobReleaseService
    ) {
        parent::__construct($context, $pdfJobRepository, $logger);
    }

    protected function process(PdfJob $job): void
    {
        $this->jobReleaseService->approveJob((int) $job->getId());
        $this->messageManager->addSuccessMessage($job->isDropship()
            ? __('The Printess dropship items of this order were approved and sent to Printess as one group. See the Printess Production tab for the result.')
            : __('The Printess item was approved and will be submitted by the next cron run.'));
    }
}
