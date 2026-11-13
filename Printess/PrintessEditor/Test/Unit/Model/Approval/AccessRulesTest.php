<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Test\Unit\Model\Approval;

use PHPUnit\Framework\TestCase;
use Printess\PrintessEditor\Model\Approval\AccessRules;
use Printess\PrintessEditor\Model\Approval\Workflow;

final class AccessRulesTest extends TestCase
{
    private AccessRules $rules;

    protected function setUp(): void
    {
        $this->rules = new AccessRules();
    }

    public function testSubmitterMustOwnTheProject(): void
    {
        self::assertTrue($this->rules->canActAsSubmitter(5, 5));
        self::assertFalse($this->rules->canActAsSubmitter(6, 5));
        self::assertFalse($this->rules->canActAsSubmitter(0, 0));
    }

    public function testSupervisorScope(): void
    {
        $approve = Workflow::ACTION_APPROVE;
        // same website, role, enabled
        self::assertTrue($this->rules->canActAsSupervisor(9, true, 1, 1, 5, true, $approve));
        // no role
        self::assertFalse($this->rules->canActAsSupervisor(9, false, 1, 1, 5, true, $approve));
        // other website
        self::assertFalse($this->rules->canActAsSupervisor(9, true, 2, 1, 5, true, $approve));
        // feature disabled for the submission's store
        self::assertFalse($this->rules->canActAsSupervisor(9, true, 1, 1, 5, false, $approve));
        // guest
        self::assertFalse($this->rules->canActAsSupervisor(0, true, 1, 1, 5, true, $approve));
        // own submission: no approval, but comments are fine
        self::assertFalse($this->rules->canActAsSupervisor(5, true, 1, 1, 5, true, $approve));
        self::assertFalse($this->rules->canActAsSupervisor(5, true, 1, 1, 5, true, Workflow::ACTION_START_EDIT));
        self::assertTrue($this->rules->canActAsSupervisor(5, true, 1, 1, 5, true, Workflow::ACTION_COMMENT));
    }

    public function testNeedsApproval(): void
    {
        self::assertFalse($this->rules->needsApproval(false, false, false));
        self::assertTrue($this->rules->needsApproval(true, false, false));
        self::assertFalse($this->rules->needsApproval(true, true, false));
        self::assertTrue($this->rules->needsApproval(true, true, true));
    }

    public function testEditLock(): void
    {
        $now = 1000;
        self::assertFalse($this->rules->isLockedByOther(null, null, 9, $now));
        self::assertTrue($this->rules->isLockedByOther(7, 2000, 9, $now));
        self::assertFalse($this->rules->isLockedByOther(9, 2000, 9, $now), 'own lock');
        self::assertFalse($this->rules->isLockedByOther(7, 900, 9, $now), 'expired');
        self::assertTrue($this->rules->isLockExpired(1000, $now));
        self::assertFalse($this->rules->isLockExpired(1001, $now));
        self::assertFalse($this->rules->isLockExpired(null, $now));
    }
}
