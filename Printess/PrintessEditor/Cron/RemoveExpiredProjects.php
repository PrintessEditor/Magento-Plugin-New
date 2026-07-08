<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Cron;

use Printess\PrintessEditor\Model\ProjectConfig;
use Printess\PrintessEditor\Model\ResourceModel\Project as ProjectResource;
use Printess\PrintessEditor\Model\ResourceModel\Project\CollectionFactory;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;

class RemoveExpiredProjects
{
    private const LOCK_NAME = 'printess_remove_expired_projects';

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly ProjectResource $projectResource,
        private readonly ProjectConfig $config,
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

            $projects = $this->collectionFactory->create();
            $projects->addFieldToFilter('store_id', ['in' => $enabledStoreIds]);
            $projects->addFieldToFilter('ordered_at', ['null' => true]);
            $projects->addFieldToFilter('expires_at', ['notnull' => true]);
            $projects->addFieldToFilter('expires_at', ['lteq' => $this->dateTime->gmtDate()]);
            $projects->setPageSize(1000);

            foreach ($projects as $project) {
                try {
                    $this->projectResource->delete($project);
                } catch (\Throwable $exception) {
                    $this->logger->error('Unable to remove expired Printess project.', [
                        'project_id' => $project->getId(),
                        'exception' => $exception
                    ]);
                }
            }
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }
}
