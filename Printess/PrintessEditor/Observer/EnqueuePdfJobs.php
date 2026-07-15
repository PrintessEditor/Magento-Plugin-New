<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Printess\PrintessEditor\Model\PdfJob;
use Printess\PrintessEditor\Model\PdfJobFactory;
use Printess\PrintessEditor\Model\ResourceModel\PdfJob as PdfJobResource;
use Psr\Log\LoggerInterface;

/**
 * Fires on sales_order_place_after.
 *
 * For each order item that has a Printess save token, creates a row in
 * printess_pdf_jobs with status=pending.  The actual produce() call and
 * PDF download happen asynchronously in Cron\ProducePdf (every 5 min).
 *
 * Only Printess items are touched; orders with no Printess items result
 * in zero rows being inserted and have no further cost.
 */
class EnqueuePdfJobs implements ObserverInterface
{
    public function __construct(
        private readonly PdfJobFactory $pdfJobFactory,
        private readonly PdfJobResource $pdfJobResource,
        private readonly LoggerInterface $logger
    ) {}

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getOrder();

        foreach ($order->getAllVisibleItems() as $item) {
            $saveToken = $item->getProductOptions()['additional_options']['printess_save_token']['value'] ?? null;

            if (empty($saveToken)) {
                continue; // not a Printess item — skip entirely
            }

            try {
                /** @var PdfJob $job */
                $job = $this->pdfJobFactory->create();
                $job->setData([
                    'order_id'      => (int) $order->getId(),
                    'order_item_id' => (int) $item->getId(),
                    'save_token'    => (string) $saveToken,
                    'status'        => PdfJob::STATUS_PENDING,
                ]);
                $this->pdfJobResource->save($job);
            } catch (\Throwable $e) {
                $this->logger->error('Printess: failed to enqueue PDF job for order item', [
                    'order_id'      => $order->getId(),
                    'order_item_id' => $item->getId(),
                    'error'         => $e->getMessage(),
                ]);
            }
        }
    }
}
