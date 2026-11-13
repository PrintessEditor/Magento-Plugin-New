<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Approval;

/**
 * SUP-14: which email goes to whom after a workflow action. PURE.
 */
final class ApprovalNotificationRules
{
    public const TO_SUBMITTER       = 'submitter';
    public const TO_SUPERVISORS     = 'supervisors';      // Default Order Supervisor, else all of the website
    public const TO_LAST_SUPERVISOR = 'last_supervisor';  // the supervisor who acted last, else as TO_SUPERVISORS

    public const NOTIFICATIONS = [
        'submitted', 'changes_requested', 'supervisor_changed', 'approved', 'accepted', 'replied', 'retracted', 'comment',
    ];

    /**
     * @return array{0:string,1:string}|null [notification (template key), audience] or null for no email
     */
    public function plan(string $action, string $role, bool $notifyOnRetract): ?array
    {
        return match ($action) {
            Workflow::ACTION_SUBMIT          => ['submitted', self::TO_SUPERVISORS],
            Workflow::ACTION_REQUEST_CHANGES => ['changes_requested', self::TO_SUBMITTER],
            Workflow::ACTION_SAVE_EDIT       => ['supervisor_changed', self::TO_SUBMITTER],
            Workflow::ACTION_APPROVE         => ['approved', self::TO_SUBMITTER],
            Workflow::ACTION_ACCEPT          => ['accepted', self::TO_LAST_SUPERVISOR],
            Workflow::ACTION_REPLY           => ['replied', self::TO_LAST_SUPERVISOR],
            Workflow::ACTION_RETRACT         => $notifyOnRetract ? ['retracted', self::TO_SUPERVISORS] : null,
            Workflow::ACTION_COMMENT         => $role === Workflow::ROLE_SUPERVISOR
                ? ['comment', self::TO_SUBMITTER]
                : ['comment', self::TO_LAST_SUPERVISOR],
            default                          => null,
        };
    }
}
