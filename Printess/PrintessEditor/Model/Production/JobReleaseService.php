<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Production;

use Magento\Framework\Exception\LocalizedException;
use Printess\PrintessEditor\Model\PdfJob;
use Printess\PrintessEditor\Model\PdfJobRepository;
use Psr\Log\LoggerInterface;

/**
 * Manual admin approval: awaiting_approval → pending.
 *
 * Print jobs are then submitted by the next cron run. Dropship jobs of one order form one group (Printess combines
 * them into one shipment), so approving any of them approves every awaiting_approval dropship job of that order and
 * sends that group to Printess right away (Shopware "Approve and send to production").
 *
 * The dropship approval (awaiting_approval → pending) and the send run together under the order's send lock
 * (JobSubmitter::sendDropshipGroupLocked()) on jobs reloaded inside it, so a cron run or a concurrent approval can
 * never send the group twice or split it.
 */
class JobReleaseService
{
    public function __construct(
        private readonly PdfJobRepository $pdfJobRepository,
        private readonly LoggerInterface $logger,
        private readonly JobSubmitter $jobSubmitter
    ) {
    }

    /**
     * @throws LocalizedException when the job does not exist or is not awaiting approval (also when it was approved
     *     by a concurrent request meanwhile, or the group is being sent right now)
     */
    public function approveJob(int $jobId): void
    {
        $job = $this->pdfJobRepository->getById($jobId);
        if ((string)$job->getData('status') !== PdfJob::STATUS_AWAITING_APPROVAL) {
            throw new LocalizedException(__('Printess job %1 is not awaiting approval.', $jobId));
        }

        if (!$job->isDropship()) {
            $this->approve($job, 'job');
            return;
        }

        $orderId = (int)$job->getData('order_id');
        $this->jobSubmitter->sendDropshipGroupLocked($orderId, function () use ($jobId, $orderId): array {
            $fresh = $this->pdfJobRepository->getById($jobId);
            if ((string)$fresh->getData('status') !== PdfJob::STATUS_AWAITING_APPROVAL) {
                throw new LocalizedException(__(
                    'Printess job %1 is no longer awaiting approval: the dropship group was already approved and is '
                    . 'being sent or was sent. Reload the page to see the current state.',
                    $jobId
                ));
            }
            return $this->approveDropshipGroup($orderId, 'job');
        });
    }

    /**
     * @throws LocalizedException when the order has no job awaiting approval
     */
    public function approveOrder(int $orderId): void
    {
        $printApproved = 0;
        $hasDropship = false;
        foreach ($this->pdfJobRepository->getByOrderId($orderId) as $job) {
            if ((string)$job->getData('status') !== PdfJob::STATUS_AWAITING_APPROVAL) {
                continue;
            }
            if ($job->isDropship()) {
                $hasDropship = true; // approved below, inside the send lock
                continue;
            }
            $this->approve($job, 'order');
            $printApproved++;
        }

        if (!$hasDropship) {
            if ($printApproved === 0) {
                throw new LocalizedException(__('No Printess job of this order is awaiting approval.'));
            }
            return;
        }

        $this->jobSubmitter->sendDropshipGroupLocked($orderId, function () use ($orderId, $printApproved): array {
            $ids = $this->approveDropshipGroup($orderId, 'order');
            if ($ids === [] && $printApproved === 0) {
                throw new LocalizedException(__(
                    'No Printess job of this order is awaiting approval any more (approved by another request).'
                ));
            }
            return $ids;
        });
    }

    /**
     * Approves every awaiting_approval dropship job of the order (reloaded; call inside the send lock).
     *
     * @return int[] the approved job ids
     */
    private function approveDropshipGroup(int $orderId, string $scope): array
    {
        $ids = [];
        foreach ($this->getDropshipGroup($orderId) as $member) {
            if ((string)$member->getData('status') === PdfJob::STATUS_AWAITING_APPROVAL) {
                $this->approve($member, $scope);
                $ids[] = (int)$member->getId();
            }
        }
        return $ids;
    }

    /**
     * All dropship jobs of the order: a Magento order has one shipping address, so it has one dropship group
     * (the linked id changes on every send, so it is not used to find the group).
     *
     * @return PdfJob[]
     */
    private function getDropshipGroup(int $orderId): array
    {
        return array_filter(
            $this->pdfJobRepository->getByOrderId($orderId),
            static fn (PdfJob $j): bool => $j->isDropship()
        );
    }

    private function approve(PdfJob $job, string $scope): void
    {
        $job->setData('status', PdfJob::STATUS_PENDING);
        $job->appendHistory(['event' => 'approved', 'status' => PdfJob::STATUS_PENDING, 'scope' => $scope]);
        $this->pdfJobRepository->save($job);
        $this->logger->info('Printess: job approved for production', [
            'job_id'   => $job->getId(),
            'order_id' => $job->getData('order_id'),
            'scope'    => $scope,
        ]);
    }
}
