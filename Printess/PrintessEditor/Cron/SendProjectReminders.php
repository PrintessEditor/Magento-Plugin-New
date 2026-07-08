<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Cron;

use Printess\PrintessEditor\Model\Project;
use Printess\PrintessEditor\Model\ProjectConfig;
use Printess\PrintessEditor\Model\ProjectMailer;
use Printess\PrintessEditor\Model\ResourceModel\Project as ProjectResource;
use Printess\PrintessEditor\Model\ResourceModel\Project\Collection;
use Printess\PrintessEditor\Model\ResourceModel\Project\CollectionFactory;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;

class SendProjectReminders
{
    private const LOCK_NAME = 'printess_send_project_reminders';

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly ProjectResource $projectResource,
        private readonly ProjectConfig $config,
        private readonly ProjectMailer $mailer,
        private readonly DateTime $dateTime,
        private readonly LockManagerInterface $lockManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->lockManager->lock(self::LOCK_NAME, 0)) {
            return;
        }

        try {
            $enabledStoreIds = $this->config->getEnabledStoreIds();
            if (empty($enabledStoreIds)) {
                return;
            }

            $remaining = $this->config->getEmailBatchLimit();
            foreach ($this->getCandidates($enabledStoreIds) as $project) {
                if ($remaining <= 0) {
                    break;
                }

                try {
                    if ($this->sendDueReminder($project)) {
                        --$remaining;
                    }
                } catch (\Throwable $exception) {
                    $this->logger->error('Unable to send Printess project reminder.', [
                        'project_id' => $project->getId(),
                        'exception' => $exception
                    ]);
                }
            }
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }

    private function getCandidates(array $enabledStoreIds): Collection
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('store_id', ['in' => $enabledStoreIds]);
        $collection->addFieldToFilter('expires_at', ['notnull' => true]);
        $collection->addFieldToFilter('expires_at', ['gt' => $this->dateTime->gmtDate()]);
        $collection->addFieldToFilter('ordered_at', ['null' => true]);
        $collection->getSelect()->where(
            'order_reminder_sent_at IS NULL OR removal_reminder_sent_at IS NULL'
        );
        $collection->setOrder('expires_at', 'ASC');
        $collection->setPageSize(1000);

        return $collection;
    }

    private function sendDueReminder(Project $project): bool
    {
        $storeId = (int) $project->getData('store_id');
        if (!$this->config->isEnabled($storeId)) {
            return false;
        }

        $now = $this->dateTime->gmtTimestamp();

        try {
            $expiresAt = (new \DateTime((string) $project->getData('expires_at'), new \DateTimeZone('UTC')))->getTimestamp();
            $updatedAt = (new \DateTime((string) $project->getData('updated_at'), new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Throwable) {
            return false;
        }

        // Order reminder takes priority: customer should hear "please order" before "it's being deleted".
        $orderReminderDays = $this->config->getOrderReminderDays($storeId);
        $orderDue = $orderReminderDays !== null
            && $updatedAt <= $now - ($orderReminderDays * 86400);
        if (!$project->getData('ordered_at') && !$project->getData('order_reminder_sent_at') && $orderDue) {
            $this->mailer->send($project, $this->config->getOrderReminderTemplate($storeId));
            $project->setData('order_reminder_sent_at', $this->dateTime->gmtDate());
            $this->projectResource->save($project);
            return true;
        }

        $removalReminderDays = $this->config->getRemovalReminderDays($storeId);
        $removalDue = $removalReminderDays !== null
            && $expiresAt <= $now + ($removalReminderDays * 86400);
        if (!$project->getData('ordered_at') && !$project->getData('removal_reminder_sent_at') && $removalDue) {
            $this->mailer->send($project, $this->config->getRemovalReminderTemplate($storeId));
            $project->setData('removal_reminder_sent_at', $this->dateTime->gmtDate());
            $this->projectResource->save($project);
            return true;
        }

        return false;
    }
}
