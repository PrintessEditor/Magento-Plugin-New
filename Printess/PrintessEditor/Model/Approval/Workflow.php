<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Approval;

/**
 * Supervisor approval workflow: every legal status transition, who may trigger it, and what each status means for
 * editing and ordering.
 *
 * PURE: no Magento dependencies (same pattern as Production\InitialStatusPolicy), so the whole transition table is
 * unit-tested without a Magento installation.
 *
 * "No submission yet" is represented by STATUS_NONE ('') so a project that was never submitted runs through the same
 * table as a retracted one.
 */
final class Workflow
{
    public const STATUS_NONE                = '';
    public const STATUS_SUBMITTED           = 'submitted';
    public const STATUS_SUPERVISOR_EDITING  = 'supervisor_editing';
    public const STATUS_NEEDS_REVISION      = 'needs_revision';
    public const STATUS_AWAITING_ACCEPTANCE = 'awaiting_acceptance';
    public const STATUS_APPROVED            = 'approved';
    public const STATUS_ORDERED             = 'ordered';
    public const STATUS_RETRACTED           = 'retracted';

    public const ROLE_SUBMITTER  = 'submitter';
    public const ROLE_SUPERVISOR = 'supervisor';
    public const ROLE_SYSTEM     = 'system';

    public const ACTION_SUBMIT          = 'submit';
    public const ACTION_RETRACT         = 'retract';
    public const ACTION_APPROVE         = 'approve';
    public const ACTION_REQUEST_CHANGES = 'request_changes';
    public const ACTION_START_EDIT      = 'start_edit';
    public const ACTION_CANCEL_EDIT     = 'cancel_edit';
    public const ACTION_LOCK_EXPIRED    = 'lock_expired';
    public const ACTION_SAVE_EDIT       = 'save_edit';
    public const ACTION_ACCEPT          = 'accept';
    public const ACTION_REVISE          = 'revise';
    public const ACTION_REPLY           = 'reply';
    public const ACTION_MARK_ORDERED    = 'mark_ordered';
    public const ACTION_COMMENT         = 'comment';

    /** Actions that need a non-empty message. */
    private const MESSAGE_REQUIRED = [self::ACTION_REQUEST_CHANGES, self::ACTION_REPLY];

    /**
     * action => [role => [fromStatus => toStatus]]. A toStatus of null means "status unchanged" (comment).
     */
    private const TRANSITIONS = [
        self::ACTION_SUBMIT => [self::ROLE_SUBMITTER => [
            self::STATUS_NONE           => self::STATUS_SUBMITTED,
            self::STATUS_RETRACTED      => self::STATUS_SUBMITTED,
            self::STATUS_NEEDS_REVISION => self::STATUS_SUBMITTED,
            // Edit & Reorder of an ordered design starts a new round.
            self::STATUS_ORDERED        => self::STATUS_SUBMITTED,
        ]],
        self::ACTION_RETRACT => [self::ROLE_SUBMITTER => [
            self::STATUS_SUBMITTED => self::STATUS_RETRACTED,
        ]],
        self::ACTION_APPROVE => [self::ROLE_SUPERVISOR => [
            self::STATUS_SUBMITTED => self::STATUS_APPROVED,
        ]],
        self::ACTION_REQUEST_CHANGES => [self::ROLE_SUPERVISOR => [
            self::STATUS_SUBMITTED => self::STATUS_NEEDS_REVISION,
        ]],
        self::ACTION_START_EDIT => [self::ROLE_SUPERVISOR => [
            self::STATUS_SUBMITTED => self::STATUS_SUPERVISOR_EDITING,
        ]],
        self::ACTION_CANCEL_EDIT => [self::ROLE_SUPERVISOR => [
            self::STATUS_SUPERVISOR_EDITING => self::STATUS_SUBMITTED,
        ]],
        self::ACTION_LOCK_EXPIRED => [self::ROLE_SYSTEM => [
            self::STATUS_SUPERVISOR_EDITING => self::STATUS_SUBMITTED,
        ]],
        self::ACTION_SAVE_EDIT => [self::ROLE_SUPERVISOR => [
            self::STATUS_SUPERVISOR_EDITING => self::STATUS_AWAITING_ACCEPTANCE,
        ]],
        self::ACTION_ACCEPT => [self::ROLE_SUBMITTER => [
            self::STATUS_AWAITING_ACCEPTANCE => self::STATUS_APPROVED,
        ]],
        self::ACTION_REVISE => [self::ROLE_SUBMITTER => [
            self::STATUS_AWAITING_ACCEPTANCE => self::STATUS_NEEDS_REVISION,
            self::STATUS_APPROVED            => self::STATUS_NEEDS_REVISION,
        ]],
        self::ACTION_REPLY => [self::ROLE_SUBMITTER => [
            self::STATUS_AWAITING_ACCEPTANCE => self::STATUS_SUBMITTED,
        ]],
        self::ACTION_MARK_ORDERED => [self::ROLE_SYSTEM => [
            self::STATUS_APPROVED => self::STATUS_ORDERED,
        ]],
        self::ACTION_COMMENT => [
            self::ROLE_SUBMITTER => [
                self::STATUS_SUBMITTED           => null,
                self::STATUS_SUPERVISOR_EDITING  => null,
                self::STATUS_NEEDS_REVISION      => null,
                self::STATUS_AWAITING_ACCEPTANCE => null,
                self::STATUS_APPROVED            => null,
            ],
            self::ROLE_SUPERVISOR => [
                self::STATUS_SUBMITTED           => null,
                self::STATUS_SUPERVISOR_EDITING  => null,
                self::STATUS_NEEDS_REVISION      => null,
                self::STATUS_AWAITING_ACCEPTANCE => null,
                self::STATUS_APPROVED            => null,
            ],
        ],
    ];

    /** @return string[] every real status (without STATUS_NONE) */
    public static function allStatuses(): array
    {
        return [
            self::STATUS_SUBMITTED, self::STATUS_SUPERVISOR_EDITING, self::STATUS_NEEDS_REVISION,
            self::STATUS_AWAITING_ACCEPTANCE, self::STATUS_APPROVED, self::STATUS_ORDERED, self::STATUS_RETRACTED,
        ];
    }

    /** @return string[] statuses for which isActive() is true */
    public static function activeStatuses(): array
    {
        return [
            self::STATUS_SUBMITTED, self::STATUS_SUPERVISOR_EDITING, self::STATUS_NEEDS_REVISION,
            self::STATUS_AWAITING_ACCEPTANCE, self::STATUS_APPROVED,
        ];
    }

    /** @return string[] */
    public static function allActions(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    /**
     * Returns the status after $action, or throws InvalidTransitionException.
     */
    public function transition(string $status, string $action, string $role, ?string $message = null): string
    {
        if (!$this->isAllowed($status, $action, $role)) {
            throw new InvalidTransitionException(sprintf(
                'Action "%s" is not allowed for role "%s" in status "%s".',
                $action,
                $role,
                $status === '' ? 'none' : $status
            ));
        }
        if (in_array($action, self::MESSAGE_REQUIRED, true) && trim((string) $message) === '') {
            throw new InvalidTransitionException(sprintf('Action "%s" needs a message.', $action));
        }
        if ($action === self::ACTION_COMMENT && trim((string) $message) === '') {
            throw new InvalidTransitionException('A comment needs a message.');
        }
        return self::TRANSITIONS[$action][$role][$status] ?? $status;
    }

    public function isAllowed(string $status, string $action, string $role): bool
    {
        return isset(self::TRANSITIONS[$action][$role])
            && array_key_exists($status, self::TRANSITIONS[$action][$role]);
    }

    public function requiresMessage(string $action): bool
    {
        return in_array($action, self::MESSAGE_REQUIRED, true) || $action === self::ACTION_COMMENT;
    }

    /** @return string[] actions $role may trigger in $status (system actions excluded for non-system roles) */
    public function allowedActions(string $status, string $role): array
    {
        $out = [];
        foreach (self::TRANSITIONS as $action => $roles) {
            if (isset($roles[$role]) && array_key_exists($status, $roles[$role])) {
                $out[] = $action;
            }
        }
        return $out;
    }

    /** The submitter may change the design (Continue editing / save) only in these statuses. */
    public function isEditableBySubmitter(string $status): bool
    {
        return in_array($status, [self::STATUS_NONE, self::STATUS_RETRACTED, self::STATUS_NEEDS_REVISION], true);
    }

    /** Locked = the stored design must not change and the project must not be deleted. */
    public function isLocked(string $status): bool
    {
        return in_array($status, [
            self::STATUS_SUBMITTED, self::STATUS_SUPERVISOR_EDITING,
            self::STATUS_AWAITING_ACCEPTANCE, self::STATUS_APPROVED,
        ], true);
    }

    public function isOrderable(string $status): bool
    {
        return $status === self::STATUS_APPROVED;
    }

    /** Shown under "To review" in My releasable documents. */
    public function isInSupervisorQueue(string $status): bool
    {
        return in_array($status, [self::STATUS_SUBMITTED, self::STATUS_SUPERVISOR_EDITING], true);
    }

    /** Waiting for the submitter (shown with a badge in My Projects). */
    public function needsSubmitterAction(string $status): bool
    {
        return in_array($status, [self::STATUS_NEEDS_REVISION, self::STATUS_AWAITING_ACCEPTANCE], true);
    }

    /** Statuses that keep a project alive (expiry cron must not delete it). */
    public function isActive(string $status): bool
    {
        return !in_array($status, [self::STATUS_NONE, self::STATUS_RETRACTED, self::STATUS_ORDERED], true);
    }
}
