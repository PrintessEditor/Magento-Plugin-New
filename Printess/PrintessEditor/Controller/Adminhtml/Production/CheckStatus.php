<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Adminhtml\Production;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Exception\LocalizedException;
use Printess\PrintessEditor\Model\PdfJob;
use Printess\PrintessEditor\Model\PdfJobRepository;
use Printess\PrintessEditor\Model\PrintessApiException;
use Printess\PrintessEditor\Model\PrintessApiProvider;
use Printess\PrintessEditor\Model\Production\DropshipOrderSync;
use Printess\PrintessEditor\Model\Production\RenderResultHandler;
use Psr\Log\LoggerInterface;

/**
 * Pulls the current state from Printess instead of waiting for cron or callbacks:
 * /production/status/get for the rendering job, and /dropship/orders/list for the order's dropship items.
 */
class CheckStatus extends AbstractAction
{
    public function __construct(
        Context $context,
        PdfJobRepository $pdfJobRepository,
        LoggerInterface $logger,
        private readonly PrintessApiProvider $apiProvider,
        private readonly RenderResultHandler $renderResultHandler,
        private readonly DropshipOrderSync $dropshipOrderSync
    ) {
        parent::__construct($context, $pdfJobRepository, $logger);
    }

    protected function process(PdfJob $job): void
    {
        $jobId          = (int) $job->getId();
        $printessJobId  = (string) $job->getData('printess_job_id');
        $hasDropship    = $job->isDropship() && (string) $job->getData('printess_order_id') !== '';

        if ($printessJobId === '' && !$hasDropship) {
            throw new LocalizedException(__('This item has not been submitted to Printess yet.'));
        }

        $stillRendering = false;
        if ($printessJobId !== '') {
            $storeId = $job->getData('store_id');
            try {
                $payload = $this->apiProvider
                    ->forStore($storeId !== null && $storeId !== '' ? (int) $storeId : null)
                    ->getJobStatusArray($printessJobId);
            } catch (PrintessApiException $e) {
                throw new LocalizedException(
                    __('Could not load the Printess job status: %1', $e->getPrintessMessage() ?? $e->getMessage()),
                    $e
                );
            }
            $stillRendering = array_key_exists('isFinalStatus', $payload) && $payload['isFinalStatus'] === false;
            $this->renderResultHandler->apply($job, $payload);
        }

        $synced = 0;
        if ($job->isDropship()) {
            $synced = $this->dropshipOrderSync->syncOrder((int) $job->getData('order_id'));
        }

        $job = $this->pdfJobRepository->getById($jobId);
        $this->messageManager->addSuccessMessage(__(
            'Printess status refreshed: job status "%1"%2%3.',
            (string) $job->getData('status'),
            $stillRendering ? ' (' . __('still rendering') . ')' : '',
            $job->isDropship() ? ', ' . __('%1 dropship item(s) updated', $synced) : ''
        ));
    }
}
