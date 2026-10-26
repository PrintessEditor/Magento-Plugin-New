<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Adminhtml\Production;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Printess\PrintessEditor\Helper\Config;
use Printess\PrintessEditor\Model\PdfJob;
use Printess\PrintessEditor\Model\PdfJobRepository;
use Printess\PrintessEditor\Model\Production\OrderReproductionService;
use Printess\PrintessEditor\Model\Production\RetryService;
use Psr\Log\LoggerInterface;

/**
 * POST from the Restart page (printess/production/batch, "Ask each time" mode): duplicates the order with the chosen
 * Printess items and dropships the copy under a new order number (plan §1.4 / §1.5).
 *
 * Params: job_id (the item the admin clicked "Restart production" on), reproduction_choice=copy, copy_items[]
 * (order item ids), copy_message, copy_reduce_stock. The backend action base validates the form key.
 * Everything the page offered is re-checked here: "ask" mode, a dropship item whose group may be restarted.
 * On success the admin lands on the new order; on errors back on the Restart page.
 */
class Reproduce extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Printess_PrintessEditor::production';

    public const CHOICE_COPY   = 'copy';
    public const CHOICE_RESEND = 'resend';

    /** Keeps the discount label and order comment readable; longer input is a mistake, not a message. */
    private const MESSAGE_MAX_LENGTH = 1000;

    public function __construct(
        Context $context,
        private readonly PdfJobRepository $pdfJobRepository,
        private readonly RetryService $retryService,
        private readonly OrderReproductionService $reproductionService,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $jobId = (int) $this->getRequest()->getParam('job_id');
        $orderId = 0;

        try {
            if ($jobId <= 0) {
                throw new LocalizedException(__('Missing Printess job id.'));
            }
            $job = $this->pdfJobRepository->getById($jobId);
            $orderId = (int) $job->getData('order_id');

            if ((string) $this->getRequest()->getParam('reproduction_choice') !== self::CHOICE_COPY) {
                throw new LocalizedException(__('Choose "Duplicate the order and dropship the copy" to create a copy.'));
            }
            $this->assertCopyAllowed($job, $orderId);

            $itemIds = $this->itemIds();
            if ($itemIds === []) {
                throw new LocalizedException(__('Select at least one item for the copy.'));
            }

            $message = trim((string) $this->getRequest()->getParam('copy_message'));
            if ($message === '') {
                $message = OrderReproductionService::DEFAULT_MESSAGE;
            }
            if (mb_strlen($message) > self::MESSAGE_MAX_LENGTH) {
                throw new LocalizedException(__(
                    'The message to the customer is too long (at most %1 characters).',
                    self::MESSAGE_MAX_LENGTH
                ));
            }
            $reduceStock = (string) $this->getRequest()->getParam('copy_reduce_stock') === '1';

            $copy = $this->reproductionService->copy($orderId, $itemIds, $message, $reduceStock);

            $this->logger->info('Printess: order reproduced by admin', [
                'order_id'          => $orderId,
                'job_id'            => $jobId,
                'item_ids'          => $itemIds,
                'reduce_stock'      => $reduceStock,
                'copy_order_id'     => $copy['id'],
                'copy_increment_id' => $copy['increment_id'],
            ]);
            $this->messageManager->addSuccessMessage(__('Copy created: order #%1', $copy['increment_id']));
            return $redirect->setPath('sales/order/view', ['order_id' => (int) $copy['id']]);
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Printess: order reproduction failed', [
                'order_id' => $orderId, 'job_id' => $jobId, 'error' => $e->getMessage(), 'exception' => $e,
            ]);
            $this->messageManager->addErrorMessage(__('The copy of the order could not be created: %1', $e->getMessage()));
        }

        if ($jobId > 0) {
            return $redirect->setPath('printess/production/batch', [
                'job_id' => $jobId,
                'action' => Batch::ACTION_RESTART,
            ]);
        }
        return $orderId > 0
            ? $redirect->setPath('sales/order/view', ['order_id' => $orderId])
            : $redirect->setPath('sales/order/index');
    }

    /**
     * Same conditions as the copy option on the Restart page: "ask" mode for the order's store, and a dropship item
     * that went to production before (RetryService::getResendBlockReason via Batch::assertActionAllowed).
     *
     * @throws LocalizedException
     */
    private function assertCopyAllowed(PdfJob $job, int $orderId): void
    {
        if (!$job->isDropship()) {
            throw new LocalizedException(__('Only dropship items can be reproduced as a copy of the order.'));
        }
        Batch::assertActionAllowed($job, Batch::ACTION_RESTART, $this->retryService);

        $storeId = $job->getData('store_id');
        $storeId = $storeId !== null && $storeId !== '' ? (int) $storeId : null;
        if ($storeId === null) {
            $storeId = (int) $this->orderRepository->get($orderId)->getStoreId();
        }
        if ($this->config->getDropshipReproductionMode($storeId) !== Config::REPRODUCTION_MODE_ASK) {
            throw new LocalizedException(__(
                'Copying the order is not enabled for this store: "Reproducing dropship orders" must be '
                . '"Ask each time".'
            ));
        }
    }

    /**
     * @return list<int> distinct positive order item ids
     */
    private function itemIds(): array
    {
        $raw = $this->getRequest()->getParam('copy_items');
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $value) {
            if (is_scalar($value) && ctype_digit((string) $value) && (int) $value > 0) {
                $ids[(int) $value] = (int) $value;
            }
        }
        return array_values($ids);
    }
}
