<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Test\Unit\Model\Approval;

use PHPUnit\Framework\TestCase;
use Printess\PrintessEditor\Model\Approval\ApprovalNotificationRules as R;
use Printess\PrintessEditor\Model\Approval\Workflow as W;

final class ApprovalNotificationRulesTest extends TestCase
{
    public function testPlan(): void
    {
        $r = new R();
        self::assertSame(['submitted', R::TO_SUPERVISORS], $r->plan(W::ACTION_SUBMIT, W::ROLE_SUBMITTER, true));
        self::assertSame(['changes_requested', R::TO_SUBMITTER], $r->plan(W::ACTION_REQUEST_CHANGES, W::ROLE_SUPERVISOR, true));
        self::assertSame(['supervisor_changed', R::TO_SUBMITTER], $r->plan(W::ACTION_SAVE_EDIT, W::ROLE_SUPERVISOR, true));
        self::assertSame(['approved', R::TO_SUBMITTER], $r->plan(W::ACTION_APPROVE, W::ROLE_SUPERVISOR, true));
        self::assertSame(['accepted', R::TO_LAST_SUPERVISOR], $r->plan(W::ACTION_ACCEPT, W::ROLE_SUBMITTER, true));
        self::assertSame(['replied', R::TO_LAST_SUPERVISOR], $r->plan(W::ACTION_REPLY, W::ROLE_SUBMITTER, true));
        self::assertSame(['retracted', R::TO_SUPERVISORS], $r->plan(W::ACTION_RETRACT, W::ROLE_SUBMITTER, true));
        self::assertNull($r->plan(W::ACTION_RETRACT, W::ROLE_SUBMITTER, false));
        self::assertSame(['comment', R::TO_SUBMITTER], $r->plan(W::ACTION_COMMENT, W::ROLE_SUPERVISOR, true));
        self::assertSame(['comment', R::TO_LAST_SUPERVISOR], $r->plan(W::ACTION_COMMENT, W::ROLE_SUBMITTER, true));
        foreach ([W::ACTION_START_EDIT, W::ACTION_CANCEL_EDIT, W::ACTION_LOCK_EXPIRED, W::ACTION_REVISE, W::ACTION_MARK_ORDERED] as $silent) {
            self::assertNull($r->plan($silent, W::ROLE_SUPERVISOR, true), $silent);
        }
    }
}
