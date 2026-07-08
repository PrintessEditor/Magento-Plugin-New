<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class ProjectConfig
{
    private const XML_PREFIX = 'printess_designer/projects/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function isEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PREFIX . 'enabled', ScopeInterface::SCOPE_STORE, $storeId);
    }

    /** @return int[] */
    public function getEnabledStoreIds(): array
    {
        $ids = [];
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int) $store->getId();
            if ($this->isEnabled($storeId)) {
                $ids[] = $storeId;
            }
        }
        return $ids;
    }

    public function getRetentionDays(int $storeId): int
    {
        return max(1, (int) $this->getValue('retention_days', $storeId));
    }

    /**
     * Returns the number of days after last-save before an order reminder is sent,
     * or null if the order reminder is disabled (field left blank in config).
     */
    public function getOrderReminderDays(int $storeId): ?int
    {
        $value = $this->getValue('order_reminder_after_days', $storeId);
        if ($value === null || $value === '') {
            return null;
        }
        return max(1, (int) $value);
    }

    /**
     * Returns the number of days before expiry to send a removal warning,
     * or null if the removal reminder is disabled (field left blank in config).
     */
    public function getRemovalReminderDays(int $storeId): ?int
    {
        $value = $this->getValue('removal_reminder_before_days', $storeId);
        if ($value === null || $value === '') {
            return null;
        }
        return max(1, (int) $value);
    }

    public function getEmailBatchLimit(): int
    {
        return max(1, (int) $this->scopeConfig->getValue(self::XML_PREFIX . 'email_batch_limit'));
    }

    public function getOrderReminderTemplate(int $storeId): string
    {
        return (string) ($this->getValue('order_reminder_template', $storeId)
            ?: 'printess_designer_projects_order_reminder_template');
    }

    public function getRemovalReminderTemplate(int $storeId): string
    {
        return (string) ($this->getValue('removal_reminder_template', $storeId)
            ?: 'printess_designer_projects_removal_reminder_template');
    }

    private function getValue(string $field, int $storeId): mixed
    {
        return $this->scopeConfig->getValue(self::XML_PREFIX . $field, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
