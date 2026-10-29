<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Adminhtml\Production;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Printess\PrintessEditor\Model\PdfJob;
use Printess\PrintessEditor\Model\PdfJobRepository;
use Psr\Log\LoggerInterface;

/**
 * Base for the Printess production actions on the admin order view. POST only; the backend action base
 * validates the form key for every POST. Each action redirects back to the order it belongs to.
 */
abstract class AbstractAction extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Printess_PrintessEditor::production';

    public function __construct(
        Context $context,
        protected readonly PdfJobRepository $pdfJobRepository,
        protected readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $orderId = (int) $this->getRequest()->getParam('order_id');
        try {
            $jobId = (int) $this->getRequest()->getParam('job_id');
            if ($jobId <= 0) {
                throw new LocalizedException(__('Missing Printess job id.'));
            }
            $job = $this->pdfJobRepository->getById($jobId);
            $orderId = (int) $job->getData('order_id');
            $this->process($job);
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Printess: admin production action failed', [
                'action' => static::class, 'error' => $e->getMessage(), 'exception' => $e,
            ]);
            $this->messageManager->addErrorMessage(__('The Printess action failed: %1', $e->getMessage()));
        }

        $redirect = $this->resultRedirectFactory->create();
        return $orderId > 0
            ? $redirect->setPath('sales/order/view', ['order_id' => $orderId])
            : $redirect->setPath('sales/order/index');
    }

    /**
     * Performs the action and adds a success message. Throw LocalizedException for user-facing errors.
     *
     * @throws LocalizedException
     */
    abstract protected function process(PdfJob $job): void;
}
