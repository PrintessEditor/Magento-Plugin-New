<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Approval;

/**
 * Customer-facing labels for statuses and history events (one place, so the UI, emails and the README glossary agree).
 */
class StatusLabels
{
    public function status(string $status, string $perspective = Workflow::ROLE_SUBMITTER): string
    {
        $sub = $perspective === Workflow::ROLE_SUBMITTER;
        return (string) match ($status) {
            Workflow::STATUS_SUBMITTED           => $sub ? __('In review') : __('To review'),
            Workflow::STATUS_SUPERVISOR_EDITING  => __('Being edited by a supervisor'),
            Workflow::STATUS_NEEDS_REVISION      => __('Changes requested'),
            Workflow::STATUS_AWAITING_ACCEPTANCE => $sub ? __('Supervisor made changes') : __('Waiting for customer to accept changes'),
            Workflow::STATUS_APPROVED            => __('Approved'),
            Workflow::STATUS_ORDERED             => __('Ordered'),
            Workflow::STATUS_RETRACTED           => __('Retracted'),
            default                              => __('Not submitted'),
        };
    }

    /** CSS modifier for the status pill. */
    public function tone(string $status): string
    {
        return match ($status) {
            Workflow::STATUS_SUBMITTED, Workflow::STATUS_SUPERVISOR_EDITING => 'review',
            Workflow::STATUS_NEEDS_REVISION, Workflow::STATUS_AWAITING_ACCEPTANCE => 'action',
            Workflow::STATUS_APPROVED, Workflow::STATUS_ORDERED => 'ok',
            default => 'neutral',
        };
    }

    public function event(string $type): string
    {
        return (string) match ($type) {
            Workflow::ACTION_SUBMIT          => __('Submitted for approval'),
            Workflow::ACTION_RETRACT         => __('Retracted'),
            Workflow::ACTION_APPROVE         => __('Approved'),
            Workflow::ACTION_REQUEST_CHANGES => __('Requested changes'),
            Workflow::ACTION_START_EDIT      => __('Started editing'),
            Workflow::ACTION_CANCEL_EDIT     => __('Stopped editing without changes'),
            Workflow::ACTION_LOCK_EXPIRED    => __('Editing session expired'),
            Workflow::ACTION_SAVE_EDIT       => __('Changed the design and approved it'),
            Workflow::ACTION_ACCEPT          => __('Accepted the supervisor\'s changes'),
            Workflow::ACTION_REVISE          => __('Started changing the design again'),
            Workflow::ACTION_REPLY           => __('Replied to the supervisor\'s changes'),
            Workflow::ACTION_MARK_ORDERED    => __('Ordered'),
            Workflow::ACTION_COMMENT         => __('Comment'),
            default                          => $type,
        };
    }
}
