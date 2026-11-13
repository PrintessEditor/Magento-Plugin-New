<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Block\Approval;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\DefaultPathInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\View\Element\Html\Link\Current;
use Magento\Framework\View\Element\Template\Context;
use Printess\PrintessEditor\Model\Approval\ApprovalConfig;
use Printess\PrintessEditor\Model\Approval\SupervisorProvider;
use Printess\PrintessEditor\Model\Approval\Workflow;

/**
 * SUP-09/SUP-15: customer account navigation links with a pending-count badge.
 *  - mode "supervisor": "My releasable documents" (only for supervisors), count = designs to review
 *  - mode "projects":   "My Projects", count = designs waiting for the customer
 * Account pages are not full-page cached, so counts are live.
 */
class NavLink extends Current
{
    private ?int $count = null;

    public function __construct(
        Context $context,
        DefaultPathInterface $defaultPath,
        private readonly CustomerSession $customerSession,
        private readonly ApprovalConfig $approvalConfig,
        private readonly SupervisorProvider $supervisorProvider,
        private readonly ResourceConnection $resourceConnection,
        array $data = []
    ) {
        parent::__construct($context, $defaultPath, $data);
    }

    protected function _toHtml()
    {
        if ($this->getData('mode') === 'supervisor' && !$this->isSupervisor()) {
            return '';
        }
        return parent::_toHtml();
    }

    public function getLabel()
    {
        $label = (string) parent::getLabel();
        $count = $this->getCount();
        return $count > 0 ? sprintf('%s (%d)', $label, $count) : $label;
    }

    private function isSupervisor(): bool
    {
        return $this->customerSession->isLoggedIn()
            && $this->approvalConfig->isEnabled()
            && $this->supervisorProvider->isSupervisorForWebsite(
                (int) $this->customerSession->getCustomerId(),
                (int) $this->_storeManager->getWebsite()->getId()
            );
    }

    private function getCount(): int
    {
        if ($this->count !== null) {
            return $this->count;
        }
        $this->count = 0;
        if (!$this->customerSession->isLoggedIn() || !$this->approvalConfig->isEnabled()) {
            return 0;
        }
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('printess_approval_submission'), ['COUNT(*)']);
        if ($this->getData('mode') === 'supervisor') {
            if (!$this->isSupervisor()) {
                return 0;
            }
            $select->where('website_id = ?', (int) $this->_storeManager->getWebsite()->getId())
                ->where('status IN (?)', [Workflow::STATUS_SUBMITTED, Workflow::STATUS_SUPERVISOR_EDITING]);
        } else {
            $select->where('customer_id = ?', (int) $this->customerSession->getCustomerId())
                ->where('status IN (?)', [Workflow::STATUS_NEEDS_REVISION, Workflow::STATUS_AWAITING_ACCEPTANCE]);
        }
        $this->count = (int) $connection->fetchOne($select);
        return $this->count;
    }
}
