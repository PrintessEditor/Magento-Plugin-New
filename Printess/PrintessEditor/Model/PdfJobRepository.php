<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Printess\PrintessEditor\Model\ResourceModel\PdfJob as PdfJobResource;
use Printess\PrintessEditor\Model\ResourceModel\PdfJob\Collection;
use Printess\PrintessEditor\Model\ResourceModel\PdfJob\CollectionFactory;

/**
 * Single access point for printess_pdf_jobs so cron, callbacks and admin code
 * share the same lookups (nonce, Printess job id, linked order).
 */
class PdfJobRepository
{
    public function __construct(
        private readonly PdfJobResource $resource,
        private readonly PdfJobFactory $pdfJobFactory,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $jobId): PdfJob
    {
        $job = $this->pdfJobFactory->create();
        $this->resource->load($job, $jobId);
        if (!$job->getId()) {
            throw new NoSuchEntityException(__('Printess job with id "%1" does not exist.', $jobId));
        }
        return $job;
    }

    public function getByNonce(string $nonce): ?PdfJob
    {
        return $nonce === '' ? null : $this->getOneBy('nonce', $nonce);
    }

    public function getByPrintessJobId(string $printessJobId): ?PdfJob
    {
        return $printessJobId === '' ? null : $this->getOneBy('printess_job_id', $printessJobId);
    }

    /**
     * @return PdfJob[]
     */
    public function getByOrderId(int $orderId): array
    {
        return $this->getListBy('order_id', $orderId);
    }

    /**
     * @return PdfJob[]
     */
    public function getByLinkedOrderId(string $linkedOrderId): array
    {
        return $linkedOrderId === '' ? [] : $this->getListBy('linked_order_id', $linkedOrderId);
    }

    /**
     * Oldest first, so cron batches drain the queue in FIFO order.
     *
     * @param string|string[] $status
     * @return PdfJob[]
     */
    public function getByStatus(string|array $status, int $limit = 50): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', is_array($status) ? ['in' => $status] : $status);
        $collection->setOrder('job_id', Collection::SORT_ORDER_ASC);
        $collection->setPageSize(max(1, $limit));
        $collection->setCurPage(1);
        return array_values($collection->getItems());
    }

    /**
     * Keyset page: jobs with the given status and job_id > $afterJobId, ascending by job_id.
     * Lets cron walk a whole status queue without OFFSET paging (stable while rows change status).
     *
     * @param string|string[] $status
     * @return PdfJob[]
     */
    public function getByStatusAfterId(string|array $status, int $afterJobId, int $limit = 200): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', is_array($status) ? ['in' => $status] : $status);
        $collection->addFieldToFilter('job_id', ['gt' => max(0, $afterJobId)]);
        $collection->setOrder('job_id', Collection::SORT_ORDER_ASC);
        $collection->setPageSize(max(1, $limit));
        $collection->setCurPage(1);
        return array_values($collection->getItems());
    }

    /**
     * @throws CouldNotSaveException
     */
    public function save(PdfJob $job): void
    {
        try {
            $this->resource->save($job);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(
                __('Could not save Printess job: %1', $e->getMessage()),
                $e
            );
        }
    }

    private function getOneBy(string $field, string|int $value): ?PdfJob
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter($field, $value);
        $collection->setPageSize(1);
        $collection->setCurPage(1);
        /** @var PdfJob|null $job */
        $job = $collection->getFirstItem();
        return $job && $job->getId() ? $job : null;
    }

    /**
     * @return PdfJob[]
     */
    private function getListBy(string $field, string|int $value): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter($field, $value);
        $collection->setOrder('job_id', Collection::SORT_ORDER_ASC);
        return array_values($collection->getItems());
    }
}
