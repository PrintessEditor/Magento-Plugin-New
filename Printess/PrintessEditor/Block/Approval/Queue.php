<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Block\Approval;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Theme\Block\Html\Pager;
use Printess\PrintessEditor\Model\Approval\StatusLabels;
use Printess\PrintessEditor\Model\Approval\Workflow;
use Printess\PrintessEditor\Model\ResourceModel\ApprovalSubmission\Collection;
use Printess\PrintessEditor\Model\ResourceModel\ApprovalSubmission\CollectionFactory;

/**
 * SUP-09: "My releasable documents" — every submission of the supervisor's website, grouped in tabs.
 */
class Queue extends Template
{
    public const TABS = [
        'review'   => [Workflow::STATUS_SUBMITTED, Workflow::STATUS_SUPERVISOR_EDITING],
        'waiting'  => [Workflow::STATUS_NEEDS_REVISION, Workflow::STATUS_AWAITING_ACCEPTANCE],
        'approved' => [Workflow::STATUS_APPROVED],
        'ordered'  => [Workflow::STATUS_ORDERED],
    ];

    private ?Collection $collection = null;

    /** @var array<int,string>|null submission id => latest message */
    private ?array $lastMessages = null;

    public function __construct(
        Context $context,
        private readonly CollectionFactory $collectionFactory,
        private readonly ResourceConnection $resourceConnection,
        private readonly StatusLabels $statusLabels,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getTab(): string
    {
        $tab = (string) $this->getRequest()->getParam('tab', 'review');
        return isset(self::TABS[$tab]) ? $tab : 'review';
    }

    public function getSearch(): string
    {
        return trim((string) $this->getRequest()->getParam('q', ''));
    }

    /** @return array<string,string> */
    public function getTabLabels(): array
    {
        return [
            'review'   => (string) __('To review'),
            'waiting'  => (string) __('Waiting for customer'),
            'approved' => (string) __('Approved'),
            'ordered'  => (string) __('Ordered'),
        ];
    }

    public function getTabCount(string $tab): int
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('printess_approval_submission'), ['COUNT(*)'])
            ->where('website_id = ?', $this->getWebsiteId())
            ->where('status IN (?)', self::TABS[$tab]);
        return (int) $connection->fetchOne($select);
    }

    public function getTabUrl(string $tab): string
    {
        return $this->getUrl('printess/approval/index', ['_query' => ['tab' => $tab]]);
    }

    public function getCollection(): Collection
    {
        if ($this->collection === null) {
            $collection = $this->collectionFactory->create();
            $collection->addFieldToFilter('main_table.website_id', $this->getWebsiteId())
                ->addFieldToFilter('main_table.status', ['in' => self::TABS[$this->getTab()]]);
            $collection->getSelect()
                ->join(
                    ['p' => $collection->getTable('printess_projects')],
                    'p.project_id = main_table.project_id',
                    ['project_name' => 'p.name', 'thumbnail_url' => 'p.thumbnail_url', 'product_name' => 'p.product_name']
                )
                ->join(
                    ['c' => $collection->getTable('customer_entity')],
                    'c.entity_id = main_table.customer_id',
                    ['submitter_firstname' => 'c.firstname', 'submitter_lastname' => 'c.lastname',
                     'submitter_email' => 'c.email']
                )
                ->joinLeft(
                    ['a' => $collection->getTable('customer_entity')],
                    'a.entity_id = main_table.assigned_supervisor_id',
                    ['assigned_firstname' => 'a.firstname', 'assigned_lastname' => 'a.lastname']
                )
                ->joinLeft(
                    ['e' => $collection->getTable('customer_entity')],
                    'e.entity_id = main_table.editing_supervisor_id',
                    ['editing_firstname' => 'e.firstname', 'editing_lastname' => 'e.lastname']
                );
            $q = $this->getSearch();
            if ($q !== '') {
                $like = '%' . addcslashes($q, '%_\\') . '%';
                $connection = $collection->getConnection();
                $collection->getSelect()->where(implode(' OR ', [
                    $connection->quoteInto('p.name LIKE ?', $like),
                    $connection->quoteInto('c.email LIKE ?', $like),
                    $connection->quoteInto("CONCAT_WS(' ', c.firstname, c.lastname) LIKE ?", $like),
                ]));
            }
            $collection->setOrder(
                $this->getTab() === 'review' ? 'main_table.submitted_at' : 'main_table.updated_at',
                $this->getTab() === 'review' ? 'ASC' : 'DESC'
            );
            $this->collection = $collection;
        }
        return $this->collection;
    }

    public function getLastMessage(int $submissionId): string
    {
        if ($this->lastMessages === null) {
            $this->lastMessages = [];
            $ids = array_map('intval', $this->getCollection()->getColumnValues('submission_id'));
            if ($ids) {
                $connection = $this->resourceConnection->getConnection();
                $table = $this->resourceConnection->getTableName('printess_approval_event');
                $latest = $connection->select()
                    ->from($table, ['submission_id', 'max_id' => 'MAX(event_id)'])
                    ->where('submission_id IN (?)', $ids)
                    ->where("message IS NOT NULL AND message <> ''")
                    ->group('submission_id');
                $select = $connection->select()
                    ->from(['ev' => $table], ['submission_id', 'message'])
                    ->join(['l' => $latest], 'l.max_id = ev.event_id', []);
                foreach ($connection->fetchPairs($select) as $id => $message) {
                    $this->lastMessages[(int) $id] = (string) $message;
                }
            }
        }
        $message = $this->lastMessages[$submissionId] ?? '';
        return mb_strlen($message) > 140 ? mb_substr($message, 0, 137) . '…' : $message;
    }

    public function getStatusLabel(string $status): string
    {
        return $this->statusLabels->status($status, Workflow::ROLE_SUPERVISOR);
    }

    public function getStatusTone(string $status): string
    {
        return $this->statusLabels->tone($status);
    }

    public function getViewUrl(int $submissionId): string
    {
        return $this->getUrl('printess/approval/view', ['id' => $submissionId]);
    }

    public function formatDateTime(?string $value): string
    {
        return $value ? $this->formatDate($value, \IntlDateFormatter::MEDIUM, true) : '';
    }

    public function getPagerHtml(): string
    {
        return $this->getChildHtml('pager');
    }

    protected function _prepareLayout(): self
    {
        parent::_prepareLayout();
        $pager = $this->getLayout()->createBlock(Pager::class, 'printess.approval.queue.pager');
        $pager->setAvailableLimit([20 => 20, 50 => 50]);
        $pager->setLimit(20);
        $pager->setCollection($this->getCollection());
        $this->setChild('pager', $pager);
        return $this;
    }

    private function getWebsiteId(): int
    {
        return (int) $this->_storeManager->getWebsite()->getId();
    }
}
