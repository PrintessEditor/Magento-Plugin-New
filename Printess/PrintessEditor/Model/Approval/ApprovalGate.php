<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Approval;

use Magento\Framework\App\ResourceConnection;

/**
 * Ordering gate (SUP-13): does a customer need approval, and was a given save token approved for them?
 *
 * Approval belongs to one design (save token): every token a supervisor approved, or the submitter accepted, is
 * recorded as an approve/accept event, so an unchanged approved design stays orderable (reorder) after later rounds.
 */
class ApprovalGate
{
    public function __construct(
        private readonly ApprovalConfig $approvalConfig,
        private readonly SupervisorProvider $supervisorProvider,
        private readonly AccessRules $accessRules,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->approvalConfig->isEnabled($storeId);
    }

    public function needsApproval(int $customerId, ?int $storeId = null): bool
    {
        if (!$this->approvalConfig->isEnabled($storeId)) {
            return false;
        }
        return $this->accessRules->needsApproval(
            true,
            $customerId > 0 && $this->supervisorProvider->isSupervisor($customerId),
            $this->approvalConfig->supervisorsNeedApproval($storeId)
        );
    }

    public function isTokenApproved(int $customerId, string $saveToken): bool
    {
        $saveToken = trim($saveToken);
        if ($customerId <= 0 || $saveToken === '') {
            return false;
        }
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['e' => $this->resourceConnection->getTableName('printess_approval_event')], ['event_id'])
            ->join(
                ['s' => $this->resourceConnection->getTableName('printess_approval_submission')],
                's.submission_id = e.submission_id',
                []
            )
            ->where('s.customer_id = ?', $customerId)
            ->where('e.type IN (?)', [Workflow::ACTION_APPROVE, Workflow::ACTION_ACCEPT])
            ->where('e.save_token = ?', $saveToken)
            ->limit(1);
        return (bool) $connection->fetchOne($select);
    }
}
