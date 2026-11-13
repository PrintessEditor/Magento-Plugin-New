<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Approval;

use Magento\Framework\Exception\NoSuchEntityException;
use Printess\PrintessEditor\Model\ResourceModel\ApprovalEvent as EventResource;
use Printess\PrintessEditor\Model\ResourceModel\ApprovalEvent\CollectionFactory as EventCollectionFactory;
use Printess\PrintessEditor\Model\ResourceModel\ApprovalSubmission as SubmissionResource;
use Printess\PrintessEditor\Model\ResourceModel\ApprovalSubmission\CollectionFactory as SubmissionCollectionFactory;

/**
 * Loads and stores submissions with an optimistic lock: save() only writes when the row still has the version that
 * was loaded, so two supervisors acting at the same time can never both succeed.
 */
class SubmissionRepository
{
    private const TABLE_FIELDS_EXCLUDED = ['submission_id', 'created_at', 'updated_at', 'version'];

    /** @var string[]|null */
    private ?array $columns = null;

    public function __construct(
        private readonly SubmissionFactory $submissionFactory,
        private readonly SubmissionResource $submissionResource,
        private readonly SubmissionCollectionFactory $submissionCollectionFactory,
        private readonly EventFactory $eventFactory,
        private readonly EventResource $eventResource,
        private readonly EventCollectionFactory $eventCollectionFactory
    ) {
    }

    public function getById(int $submissionId): Submission
    {
        $submission = $this->submissionFactory->create();
        $this->submissionResource->load($submission, $submissionId);
        if (!$submission->getId()) {
            throw new NoSuchEntityException(__('This approval request no longer exists.'));
        }
        return $submission;
    }

    public function getByProjectId(int $projectId): ?Submission
    {
        $submission = $this->submissionFactory->create();
        $this->submissionResource->load($submission, $projectId, 'project_id');
        return $submission->getId() ? $submission : null;
    }

    /**
     * @param int[] $projectIds
     * @return array<int,Submission> keyed by project id
     */
    public function getByProjectIds(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }
        $collection = $this->submissionCollectionFactory->create();
        $collection->addFieldToFilter('project_id', ['in' => array_map('intval', $projectIds)]);
        $out = [];
        foreach ($collection as $submission) {
            $out[(int) $submission->getData('project_id')] = $submission;
        }
        return $out;
    }

    /** The approved (not yet ordered) submission of $customerId whose approved design is $saveToken. */
    public function findApprovedByToken(int $customerId, string $saveToken): ?Submission
    {
        $collection = $this->submissionCollectionFactory->create();
        $collection->addFieldToFilter('customer_id', $customerId)
            ->addFieldToFilter('approved_save_token', $saveToken)
            ->addFieldToFilter('status', Workflow::STATUS_APPROVED)
            ->setPageSize(1);
        $submission = $collection->getFirstItem();
        return $submission->getId() ? $submission : null;
    }

    public function create(): Submission
    {
        return $this->submissionFactory->create();
    }

    /**
     * Inserts a new submission or updates an existing one if its version is unchanged.
     *
     * @throws ConcurrentModificationException when the row was changed by someone else meanwhile
     */
    public function save(Submission $submission): void
    {
        if (!$submission->getId()) {
            $submission->setData('version', 1);
            $this->submissionResource->save($submission);
            $submission->setOrigData('version', 1);
            return;
        }

        $loadedVersion = (int) ($submission->getOrigData('version') ?? $submission->getData('version'));
        $data = array_intersect_key($submission->getData(), array_flip($this->getColumns()));
        foreach (self::TABLE_FIELDS_EXCLUDED as $field) {
            unset($data[$field]);
        }
        $data['version'] = $loadedVersion + 1;

        $connection = $this->submissionResource->getConnection();
        $affected = $connection->update(
            $this->submissionResource->getMainTable(),
            $data,
            [
                'submission_id = ?' => (int) $submission->getId(),
                'version = ?'       => $loadedVersion,
            ]
        );
        if ($affected < 1) {
            throw new ConcurrentModificationException(
                __('Someone else has just handled this design. Reload the page to see its current state.')
            );
        }
        $submission->setData('version', $loadedVersion + 1);
        $submission->setOrigData('version', $loadedVersion + 1);
    }

    public function addEvent(array $data): Event
    {
        $event = $this->eventFactory->create();
        $event->setData($data);
        $this->eventResource->save($event);
        return $event;
    }

    /** @return Event[] oldest first */
    public function getEvents(int $submissionId): array
    {
        $collection = $this->eventCollectionFactory->create();
        $collection->addFieldToFilter('submission_id', $submissionId)
            ->setOrder('created_at', 'ASC')
            ->setOrder('event_id', 'ASC');
        return array_values($collection->getItems());
    }

    public function getConnection(): \Magento\Framework\DB\Adapter\AdapterInterface
    {
        return $this->submissionResource->getConnection();
    }

    /** @return string[] */
    private function getColumns(): array
    {
        if ($this->columns === null) {
            $this->columns = array_keys(
                $this->submissionResource->getConnection()->describeTable($this->submissionResource->getMainTable())
            );
        }
        return $this->columns;
    }
}
