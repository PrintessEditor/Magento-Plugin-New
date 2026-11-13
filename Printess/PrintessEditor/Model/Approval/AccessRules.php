<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Approval;

/**
 * Who may act on a submission and who needs approval at all.
 *
 * PURE: the Magento facts (role flag, websites, config) are passed in by ApprovalService/ApprovalGate.
 */
final class AccessRules
{
    /** The submitter owns the project. */
    public function canActAsSubmitter(int $actorCustomerId, int $submitterCustomerId): bool
    {
        return $actorCustomerId > 0 && $actorCustomerId === $submitterCustomerId;
    }

    /**
     * A supervisor acts on submissions of their own website, only while approval is enabled for the submission's
     * store. Approving your own design is never allowed.
     */
    public function canActAsSupervisor(
        int $actorCustomerId,
        bool $actorIsSupervisor,
        int $actorWebsiteId,
        int $submissionWebsiteId,
        int $submitterCustomerId,
        bool $approvalEnabledForStore,
        string $action
    ): bool {
        if ($actorCustomerId <= 0 || !$actorIsSupervisor || !$approvalEnabledForStore) {
            return false;
        }
        if ($actorWebsiteId !== $submissionWebsiteId) {
            return false;
        }
        if ($actorCustomerId === $submitterCustomerId && $action !== Workflow::ACTION_COMMENT) {
            return false;
        }
        return true;
    }

    /**
     * Does this customer's design need a supervisor's approval before it may be ordered?
     * Guests never reach this (approval requires login); supervisors skip it unless the store says otherwise.
     */
    public function needsApproval(bool $approvalEnabled, bool $customerIsSupervisor, bool $supervisorsNeedApproval): bool
    {
        if (!$approvalEnabled) {
            return false;
        }
        return !$customerIsSupervisor || $supervisorsNeedApproval;
    }

    /** Edit lock is held by someone else and still valid. */
    public function isLockedByOther(?int $lockHolderId, ?int $lockExpiresAt, int $actorCustomerId, int $now): bool
    {
        if (!$lockHolderId || $lockExpiresAt === null) {
            return false;
        }
        return $lockHolderId !== $actorCustomerId && $lockExpiresAt > $now;
    }

    public function isLockExpired(?int $lockExpiresAt, int $now): bool
    {
        return $lockExpiresAt !== null && $lockExpiresAt <= $now;
    }
}
