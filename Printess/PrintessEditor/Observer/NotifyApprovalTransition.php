<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Printess\PrintessEditor\Model\Approval\ApprovalMailer;
use Printess\PrintessEditor\Model\Approval\Submission;
use Psr\Log\LoggerInterface;

/** SUP-14: printess_approval_transition_after → emails. Never throws (the action is already committed). */
class NotifyApprovalTransition implements ObserverInterface
{
    public function __construct(
        private readonly ApprovalMailer $mailer,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $submission = $observer->getData('submission');
        if (!$submission instanceof Submission) {
            return;
        }
        try {
            $this->mailer->notify(
                $submission,
                (string) $observer->getData('action'),
                (string) $observer->getData('role'),
                (int) $observer->getData('actor_id'),
                $observer->getData('message') !== null ? (string) $observer->getData('message') : null
            );
        } catch (\Throwable $e) {
            $this->logger->error('Printess approval: notification failed: ' . $e->getMessage());
        }
    }
}
