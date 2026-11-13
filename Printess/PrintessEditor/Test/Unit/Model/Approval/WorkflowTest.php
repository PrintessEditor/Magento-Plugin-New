<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Test\Unit\Model\Approval;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Printess\PrintessEditor\Model\Approval\InvalidTransitionException;
use Printess\PrintessEditor\Model\Approval\Workflow as W;

final class WorkflowTest extends TestCase
{
    /** Expected table: "status|action|role" => next status. Everything else must throw. */
    private const ALLOWED = [
        '|submit|submitter'                                  => W::STATUS_SUBMITTED,
        'retracted|submit|submitter'                         => W::STATUS_SUBMITTED,
        'needs_revision|submit|submitter'                    => W::STATUS_SUBMITTED,
        'ordered|submit|submitter'                           => W::STATUS_SUBMITTED,
        'submitted|retract|submitter'                        => W::STATUS_RETRACTED,
        'submitted|approve|supervisor'                       => W::STATUS_APPROVED,
        'submitted|request_changes|supervisor'               => W::STATUS_NEEDS_REVISION,
        'submitted|start_edit|supervisor'                    => W::STATUS_SUPERVISOR_EDITING,
        'supervisor_editing|cancel_edit|supervisor'          => W::STATUS_SUBMITTED,
        'supervisor_editing|lock_expired|system'             => W::STATUS_SUBMITTED,
        'supervisor_editing|save_edit|supervisor'            => W::STATUS_AWAITING_ACCEPTANCE,
        'awaiting_acceptance|accept|submitter'               => W::STATUS_APPROVED,
        'awaiting_acceptance|revise|submitter'               => W::STATUS_NEEDS_REVISION,
        'approved|revise|submitter'                          => W::STATUS_NEEDS_REVISION,
        'awaiting_acceptance|reply|submitter'                => W::STATUS_SUBMITTED,
        'approved|mark_ordered|system'                       => W::STATUS_ORDERED,
    ];

    private const COMMENT_STATUSES = ['submitted', 'supervisor_editing', 'needs_revision', 'awaiting_acceptance', 'approved'];

    public static function allCombinations(): iterable
    {
        $statuses = array_merge([W::STATUS_NONE], W::allStatuses());
        foreach ($statuses as $status) {
            foreach (W::allActions() as $action) {
                foreach ([W::ROLE_SUBMITTER, W::ROLE_SUPERVISOR, W::ROLE_SYSTEM] as $role) {
                    yield "$status|$action|$role" => [$status, $action, $role];
                }
            }
        }
    }

    #[DataProvider('allCombinations')]
    public function testTransitionTable(string $status, string $action, string $role): void
    {
        $key = "$status|$action|$role";
        $workflow = new W();
        $isComment = $action === W::ACTION_COMMENT;
        $commentAllowed = $isComment && $role !== W::ROLE_SYSTEM && in_array($status, self::COMMENT_STATUSES, true);

        if (array_key_exists($key, self::ALLOWED) || $commentAllowed) {
            $expected = $commentAllowed ? $status : self::ALLOWED[$key];
            self::assertTrue($workflow->isAllowed($status, $action, $role));
            self::assertSame($expected, $workflow->transition($status, $action, $role, 'a message'));
            return;
        }
        self::assertFalse($workflow->isAllowed($status, $action, $role));
        $this->expectException(InvalidTransitionException::class);
        $workflow->transition($status, $action, $role, 'a message');
    }

    public function testMessageRequired(): void
    {
        $workflow = new W();
        foreach ([
            ['submitted', W::ACTION_REQUEST_CHANGES, W::ROLE_SUPERVISOR],
            ['awaiting_acceptance', W::ACTION_REPLY, W::ROLE_SUBMITTER],
            ['submitted', W::ACTION_COMMENT, W::ROLE_SUBMITTER],
        ] as [$status, $action, $role]) {
            try {
                $workflow->transition($status, $action, $role, '   ');
                self::fail("$action without message must throw");
            } catch (InvalidTransitionException) {
                self::assertTrue($workflow->requiresMessage($action));
            }
        }
        self::assertSame(W::STATUS_APPROVED, $workflow->transition('submitted', W::ACTION_APPROVE, W::ROLE_SUPERVISOR));
    }

    public function testEditableLockedOrderable(): void
    {
        $w = new W();
        $editable = [W::STATUS_NONE, W::STATUS_RETRACTED, W::STATUS_NEEDS_REVISION];
        foreach (array_merge([W::STATUS_NONE], W::allStatuses()) as $s) {
            self::assertSame(in_array($s, $editable, true), $w->isEditableBySubmitter($s), $s);
            self::assertSame($s === W::STATUS_APPROVED, $w->isOrderable($s), $s);
        }
        self::assertTrue($w->isLocked(W::STATUS_SUBMITTED));
        self::assertTrue($w->isLocked(W::STATUS_APPROVED));
        self::assertFalse($w->isLocked(W::STATUS_ORDERED));
        self::assertTrue($w->isInSupervisorQueue(W::STATUS_SUPERVISOR_EDITING));
        self::assertFalse($w->isInSupervisorQueue(W::STATUS_RETRACTED));
        self::assertTrue($w->needsSubmitterAction(W::STATUS_AWAITING_ACCEPTANCE));
        self::assertFalse($w->isActive(W::STATUS_ORDERED));
        self::assertTrue($w->isActive(W::STATUS_APPROVED));
        foreach (array_merge([W::STATUS_NONE], W::allStatuses()) as $s) {
            self::assertSame($w->isActive($s), in_array($s, W::activeStatuses(), true), $s);
        }
    }

    public function testAllowedActionsForUi(): void
    {
        $w = new W();
        self::assertSame(['retract', 'comment'], $w->allowedActions(W::STATUS_SUBMITTED, W::ROLE_SUBMITTER));
        self::assertSame(
            ['approve', 'request_changes', 'start_edit', 'comment'],
            $w->allowedActions(W::STATUS_SUBMITTED, W::ROLE_SUPERVISOR)
        );
        self::assertSame(['accept', 'revise', 'reply', 'comment'], $w->allowedActions(W::STATUS_AWAITING_ACCEPTANCE, W::ROLE_SUBMITTER));
    }
}
