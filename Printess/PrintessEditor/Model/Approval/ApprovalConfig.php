<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Approval;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Store configuration of the supervisor approval workflow
 * (Stores → Configuration → Printess Designer → Supervisor Approval).
 */
class ApprovalConfig
{
    public const XML_PATH_ENABLED                   = 'printess_designer/supervisor_approval/enabled';
    public const XML_PATH_DEFAULT_SUPERVISOR        = 'printess_designer/supervisor_approval/default_supervisor';
    public const XML_PATH_SUPERVISORS_NEED_APPROVAL = 'printess_designer/supervisor_approval/supervisors_need_approval';
    public const XML_PATH_EDIT_LOCK_MINUTES         = 'printess_designer/supervisor_approval/edit_lock_minutes';
    public const XML_PATH_NOTIFY_ON_RETRACT         = 'printess_designer/supervisor_approval/notify_on_retract';
    public const XML_PATH_EMAIL_IDENTITY            = 'printess_designer/supervisor_approval/email_identity';
    public const XML_PATH_TEMPLATE_PREFIX           = 'printess_designer/supervisor_approval/template_';

    public const DEFAULT_EDIT_LOCK_MINUTES = 60;

    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getDefaultSupervisorId(?int $storeId = null): ?int
    {
        $value = (int) $this->scopeConfig->getValue(
            self::XML_PATH_DEFAULT_SUPERVISOR,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $value > 0 ? $value : null;
    }

    public function supervisorsNeedApproval(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SUPERVISORS_NEED_APPROVAL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getEditLockSeconds(?int $storeId = null): int
    {
        $minutes = (int) $this->scopeConfig->getValue(
            self::XML_PATH_EDIT_LOCK_MINUTES,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return 60 * ($minutes > 0 ? $minutes : self::DEFAULT_EDIT_LOCK_MINUTES);
    }

    public function notifyOnRetract(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_NOTIFY_ON_RETRACT, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getEmailIdentity(?int $storeId = null): string
    {
        return (string) ($this->scopeConfig->getValue(
            self::XML_PATH_EMAIL_IDENTITY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 'general');
    }

    public function getEmailTemplate(string $notification, ?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_TEMPLATE_PREFIX . $notification,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
