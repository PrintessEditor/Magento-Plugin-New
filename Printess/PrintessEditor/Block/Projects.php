<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Block;

use Printess\PrintessEditor\Model\Project;
use Printess\PrintessEditor\Model\ResourceModel\Project\Collection;
use Printess\PrintessEditor\Model\ResourceModel\Project\CollectionFactory;
use Magento\Customer\Model\Session;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Theme\Block\Html\Pager;

class Projects extends Template
{
    private Collection $projects;

    public function __construct(
        Context $context,
        CollectionFactory $collectionFactory,
        Session $customerSession,
        private readonly DateTime $dateTime,
        private readonly \Printess\PrintessEditor\Model\Approval\SubmissionRepository $submissionRepository,
        private readonly \Printess\PrintessEditor\Model\Approval\StatusLabels $statusLabels,
        private readonly \Printess\PrintessEditor\Model\Approval\Workflow $approvalWorkflow,
        private readonly \Printess\PrintessEditor\Model\Approval\ApprovalConfig $approvalConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);

        $this->projects = $collectionFactory->create();
        $this->projects->addFieldToFilter('customer_id', (int) $customerSession->getCustomerId());
        // Filter before the pager calculates totals; cleanup may retain ordered projects.
        $this->projects->addFieldToFilter(
            'expires_at',
            [['null' => true], ['gt' => $this->dateTime->gmtDate()]]
        );
        $this->projects->setOrder('updated_at', 'DESC');
    }

    protected function _prepareLayout(): self
    {
        parent::_prepareLayout();

        $pager = $this->getLayout()->createBlock(Pager::class, 'printess.projects.pager');
        $pager->setAvailableLimit([10 => 10, 20 => 20, 50 => 50]);
        $pager->setCollection($this->projects);
        $this->setChild('pager', $pager);

        return $this;
    }

    public function getProjects(): Collection
    {
        return $this->projects;
    }

    public function getOpenUrl(): string
    {
        return $this->getUrl('printess/project/open');
    }

    public function getRenameUrl(): string
    {
        return $this->getUrl('printess/project/rename');
    }

    public function getDeleteUrl(): string
    {
        return $this->getUrl('printess/project/delete');
    }

    public function getPagerHtml(): string
    {
        return $this->getChildHtml('pager');
    }

    public function isExpired(Project $project): bool
    {
        $expiresAt = (string) $project->getData('expires_at');
        if ($expiresAt === '') {
            return false;
        }

        try {
            return (new \DateTimeImmutable($expiresAt, new \DateTimeZone('UTC')))->getTimestamp()
                <= $this->dateTime->gmtTimestamp();
        } catch (\Throwable) {
            return true;
        }
    }

    /** @var array<int,\Printess\PrintessEditor\Model\Approval\Submission>|null */
    private ?array $submissions = null;

    public function getSubmission(Project $project): ?\Printess\PrintessEditor\Model\Approval\Submission
    {
        if (!$this->approvalConfig->isEnabled()) {
            return null; // feature off: no locks, no status (data is kept)
        }
        if ($this->submissions === null) {
            $ids = array_map('intval', $this->projects->getColumnValues('project_id'));
            $this->submissions = $this->submissionRepository->getByProjectIds($ids);
        }
        return $this->submissions[(int) $project->getId()] ?? null;
    }

    public function getApprovalStatusLabel(string $status): string
    {
        return $this->statusLabels->status($status);
    }

    public function getApprovalTone(string $status): string
    {
        return $this->statusLabels->tone($status);
    }

    public function isApprovalLocked(string $status): bool
    {
        return $this->approvalWorkflow->isLocked($status);
    }

    public function needsSubmitterAction(string $status): bool
    {
        return $this->approvalWorkflow->needsSubmitterAction($status);
    }

    public function getApprovalUrl(Project $project): string
    {
        return $this->getUrl('printess/approval/project', ['id' => (int) $project->getId()]);
    }
}
