<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Approval;

use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\StoreManagerInterface;
use Printess\PrintessEditor\Model\Project;
use Printess\PrintessEditor\Model\ProjectManager;
use Printess\PrintessEditor\Model\ResourceModel\Project as ProjectResource;
use Printess\PrintessEditor\Model\ThumbnailUrlValidator;

/**
 * Applies supervisor-approval workflow actions (SUP-03). Every controller goes through here:
 * permission check (AccessRules) → transition (Workflow) → submission + project changes → event row, all in one DB
 * transaction with an optimistic lock, then `printess_approval_transition_after` for notifications.
 */
class ApprovalService
{
    public const EVENT_TRANSITION_AFTER = 'printess_approval_transition_after';
    private const MAX_MESSAGE_LENGTH = 2000;

    public function __construct(
        private readonly SubmissionRepository $repository,
        private readonly Workflow $workflow,
        private readonly AccessRules $accessRules,
        private readonly ApprovalConfig $approvalConfig,
        private readonly ApprovalGate $approvalGate,
        private readonly SupervisorProvider $supervisorProvider,
        private readonly SupervisorResolver $supervisorResolver,
        private readonly ProjectManager $projectManager,
        private readonly ProjectResource $projectResource,
        private readonly StoreManagerInterface $storeManager,
        private readonly DateTime $dateTime,
        private readonly EventManager $eventManager,
        private readonly ThumbnailUrlValidator $thumbnailUrlValidator,
        private readonly \Printess\PrintessEditor\Model\ProjectConfig $projectConfig,
        private readonly \Magento\Catalog\Api\ProductRepositoryInterface $productRepository
    ) {
    }

    // ---------------------------------------------------------------- submitter actions

    /**
     * Submit (or resubmit) a saved project for approval.
     *
     * @param array<string,mixed>|null $buyRequest product options + qty; null keeps the stored snapshot
     */
    public function submit(int $customerId, int $projectId, ?string $message = null, ?array $buyRequest = null): Submission
    {
        $project = $this->projectManager->getOwnedProject($projectId, $customerId);
        $storeId = (int) $project->getData('store_id');
        if (!$this->approvalConfig->isEnabled($storeId)) {
            throw new LocalizedException(__('Supervisor approval is not enabled for this store.'));
        }
        if (!$this->approvalGate->needsApproval($customerId, $storeId)) {
            throw new LocalizedException(__('Your designs do not need approval. You can order this design directly.'));
        }
        if (trim((string) $project->getData('save_token')) === '') {
            throw new LocalizedException(__('Save the design before submitting it for approval.'));
        }

        $submission = $this->repository->getByProjectId($projectId);
        $isNew = $submission === null;
        if ($isNew) {
            $submission = $this->repository->create();
            $submission->setData([
                'project_id'  => $projectId,
                'customer_id' => $customerId,
                'store_id'    => $storeId,
                'website_id'  => (int) $this->storeManager->getStore($storeId)->getWebsiteId(),
                'product_id'  => $project->getData('product_id') ? (int) $project->getData('product_id') : null,
                'status'      => Workflow::STATUS_NONE,
                'round'       => 0,
            ]);
        }

        return $this->apply(
            $submission,
            Workflow::ACTION_SUBMIT,
            Workflow::ROLE_SUBMITTER,
            $customerId,
            $message,
            function (Submission $s) use ($project, $buyRequest, $storeId): array {
                $s->setData('round', (int) $s->getData('round') + 1);
                $s->setData('submitted_save_token', (string) $project->getData('save_token'));
                $s->setData('supervisor_save_token', null);
                $s->setData('supervisor_thumbnail_url', null);
                $s->setData('approved_save_token', null);
                $s->setData('approved_by', null);
                $s->setData('approved_by_name', null);
                $s->setData('approved_at', null);
                $s->setData('supervisor_edited', 0);
                $s->setData('ordered_at', null);
                $s->setData('order_id', null);
                $s->setData('editing_supervisor_id', null);
                $s->setData('edit_lock_expires_at', null);
                $s->setData('submitted_at', $this->dateTime->gmtDate());
                $s->setData('store_id', $storeId);
                $s->setData('assigned_supervisor_id', $this->supervisorResolver->resolve(
                    $s->getData('product_id') ? (int) $s->getData('product_id') : null,
                    $storeId
                ));
                if ($buyRequest !== null) {
                    $s->setData('buy_request_json', json_encode($buyRequest));
                }
                // SUP-16: a design in the approval process never expires (expiry cron / My Projects filter).
                $project->setData('expires_at', null);
                return [
                    'save_token'    => (string) $project->getData('save_token'),
                    'thumbnail_url' => (string) $project->getData('thumbnail_url'),
                ];
            },
            $project
        );
    }

    public function retract(int $customerId, int $projectId): Submission
    {
        $submission = $this->getSubmitterSubmission($customerId, $projectId);
        $this->assertNotLockedByOther($submission, $customerId, __(
            'A supervisor is editing this design right now. You can retract it once they are done.'
        ));
        $project = $this->projectManager->getOwnedProject($projectId, $customerId);
        return $this->apply(
            $submission,
            Workflow::ACTION_RETRACT,
            Workflow::ROLE_SUBMITTER,
            $customerId,
            null,
            function () use ($project): array {
                $this->restartExpiry($project);
                return [];
            },
            $project
        );
    }

    /** Submitter accepts the supervisor's changes: the supervisor's version becomes the approved design. */
    public function accept(int $customerId, int $projectId): Submission
    {
        $submission = $this->getSubmitterSubmission($customerId, $projectId);
        $project = $this->projectManager->getOwnedProject($projectId, $customerId);
        return $this->apply(
            $submission,
            Workflow::ACTION_ACCEPT,
            Workflow::ROLE_SUBMITTER,
            $customerId,
            null,
            function (Submission $s) use ($project): array {
                $token = (string) $s->getData('supervisor_save_token');
                $thumb = (string) $s->getData('supervisor_thumbnail_url');
                $this->copyDesignToProject($project, $token, $thumb);
                $s->setData('approved_save_token', $token);
                $s->setData('approved_at', $this->dateTime->gmtDate());
                $s->setData('supervisor_edited', 1);
                return ['save_token' => $token, 'thumbnail_url' => $thumb];
            },
            $project
        );
    }

    /** Submitter wants to change the design again (after a supervisor edit, or an approved design). */
    public function revise(int $customerId, int $projectId, ?string $message = null): Submission
    {
        $submission = $this->getSubmitterSubmission($customerId, $projectId);
        $project = $this->projectManager->getOwnedProject($projectId, $customerId);
        return $this->apply(
            $submission,
            Workflow::ACTION_REVISE,
            Workflow::ROLE_SUBMITTER,
            $customerId,
            $message,
            function (Submission $s) use ($project): array {
                // Continue from the supervisor's version when there is one.
                if ($s->getStatus() === Workflow::STATUS_AWAITING_ACCEPTANCE && $s->getData('supervisor_save_token')) {
                    $this->copyDesignToProject(
                        $project,
                        (string) $s->getData('supervisor_save_token'),
                        (string) $s->getData('supervisor_thumbnail_url')
                    );
                }
                $s->setData('approved_save_token', null);
                return ['save_token' => (string) $project->getData('save_token')];
            },
            $project
        );
    }

    /** Submitter answers the supervisor's changes with a comment: back to the review queue (supervisor's version). */
    public function reply(int $customerId, int $projectId, string $message): Submission
    {
        $submission = $this->getSubmitterSubmission($customerId, $projectId);
        return $this->apply(
            $submission,
            Workflow::ACTION_REPLY,
            Workflow::ROLE_SUBMITTER,
            $customerId,
            $message,
            function (Submission $s): array {
                $token = (string) ($s->getData('supervisor_save_token') ?: $s->getData('submitted_save_token'));
                $s->setData('submitted_save_token', $token);
                $s->setData('submitted_at', $this->dateTime->gmtDate());
                return ['save_token' => $token];
            }
        );
    }

    public function commentAsSubmitter(int $customerId, int $projectId, string $message): Submission
    {
        $submission = $this->getSubmitterSubmission($customerId, $projectId);
        return $this->apply($submission, Workflow::ACTION_COMMENT, Workflow::ROLE_SUBMITTER, $customerId, $message);
    }

    // ---------------------------------------------------------------- supervisor actions

    public function approve(int $supervisorId, int $submissionId, ?string $note = null): Submission
    {
        $submission = $this->getSupervisorSubmission($supervisorId, $submissionId, Workflow::ACTION_APPROVE);
        $this->assertProductAvailable($submission);
        $name = $this->contactLabel($supervisorId);
        return $this->apply(
            $submission,
            Workflow::ACTION_APPROVE,
            Workflow::ROLE_SUPERVISOR,
            $supervisorId,
            $note,
            function (Submission $s) use ($supervisorId, $name): array {
                $token = (string) $s->getData('submitted_save_token');
                $s->setData('approved_save_token', $token);
                $s->setData('approved_by', $supervisorId);
                $s->setData('approved_by_name', $name);
                $s->setData('approved_at', $this->dateTime->gmtDate());
                $s->setData('supervisor_edited', 0);
                return ['save_token' => $token];
            }
        );
    }

    public function requestChanges(int $supervisorId, int $submissionId, string $message): Submission
    {
        $submission = $this->getSupervisorSubmission($supervisorId, $submissionId, Workflow::ACTION_REQUEST_CHANGES);
        return $this->apply(
            $submission,
            Workflow::ACTION_REQUEST_CHANGES,
            Workflow::ROLE_SUPERVISOR,
            $supervisorId,
            $message
        );
    }

    public function startEdit(int $supervisorId, int $submissionId): Submission
    {
        $submission = $this->getSupervisorSubmission($supervisorId, $submissionId, Workflow::ACTION_START_EDIT);
        // Re-opening your own still-valid lock (e.g. after a page reload) is fine.
        if ($submission->getStatus() === Workflow::STATUS_SUPERVISOR_EDITING
            && (int) $submission->getData('editing_supervisor_id') === $supervisorId) {
            $submission->setData('edit_lock_expires_at', $this->lockExpiry($submission));
            $this->repository->save($submission);
            return $submission;
        }
        return $this->apply(
            $submission,
            Workflow::ACTION_START_EDIT,
            Workflow::ROLE_SUPERVISOR,
            $supervisorId,
            null,
            function (Submission $s) use ($supervisorId): array {
                $s->setData('editing_supervisor_id', $supervisorId);
                $s->setData('edit_lock_expires_at', $this->lockExpiry($s));
                return [];
            }
        );
    }

    public function cancelEdit(int $supervisorId, int $submissionId): Submission
    {
        $submission = $this->getSupervisorSubmission($supervisorId, $submissionId, Workflow::ACTION_CANCEL_EDIT);
        $this->assertOwnLock($submission, $supervisorId);
        return $this->apply(
            $submission,
            Workflow::ACTION_CANCEL_EDIT,
            Workflow::ROLE_SUPERVISOR,
            $supervisorId,
            null,
            function (Submission $s): array {
                $s->setData('editing_supervisor_id', null);
                $s->setData('edit_lock_expires_at', null);
                return [];
            }
        );
    }

    /**
     * Supervisor saved changes and marks the design approved; the submitter must accept it.
     *
     * @param array<string,mixed>|null $buyRequest options re-derived from the edited form fields
     */
    public function saveSupervisorEdit(
        int $supervisorId,
        int $submissionId,
        string $saveToken,
        ?string $thumbnailUrl,
        ?array $buyRequest,
        ?string $note
    ): Submission {
        $saveToken = trim($saveToken);
        if ($saveToken === '' || mb_strlen($saveToken) > 512) {
            throw new LocalizedException(__('The edited design could not be saved. Please try again.'));
        }
        $submission = $this->getSupervisorSubmission($supervisorId, $submissionId, Workflow::ACTION_SAVE_EDIT);
        $this->assertOwnLock($submission, $supervisorId);
        $thumb = $this->thumbnailUrlValidator->validate($thumbnailUrl);
        $name = $this->contactLabel($supervisorId);
        return $this->apply(
            $submission,
            Workflow::ACTION_SAVE_EDIT,
            Workflow::ROLE_SUPERVISOR,
            $supervisorId,
            $note,
            function (Submission $s) use ($saveToken, $thumb, $buyRequest, $supervisorId, $name): array {
                $s->setData('supervisor_save_token', $saveToken);
                $s->setData('supervisor_thumbnail_url', $thumb);
                $s->setData('editing_supervisor_id', null);
                $s->setData('edit_lock_expires_at', null);
                $s->setData('approved_by', $supervisorId);
                $s->setData('approved_by_name', $name);
                if ($buyRequest !== null) {
                    $s->setData('buy_request_json', json_encode($buyRequest));
                }
                return ['save_token' => $saveToken, 'thumbnail_url' => (string) $thumb];
            }
        );
    }

    public function commentAsSupervisor(int $supervisorId, int $submissionId, string $message): Submission
    {
        $submission = $this->getSupervisorSubmission($supervisorId, $submissionId, Workflow::ACTION_COMMENT);
        return $this->apply($submission, Workflow::ACTION_COMMENT, Workflow::ROLE_SUPERVISOR, $supervisorId, $message);
    }

    // ---------------------------------------------------------------- system actions

    public function markOrdered(Submission $submission, int $orderId): Submission
    {
        return $this->apply(
            $submission,
            Workflow::ACTION_MARK_ORDERED,
            Workflow::ROLE_SYSTEM,
            0,
            null,
            function (Submission $s) use ($orderId): array {
                $s->setData('ordered_at', $this->dateTime->gmtDate());
                $s->setData('order_id', $orderId);
                return ['save_token' => (string) $s->getData('approved_save_token')];
            }
        );
    }

    /** Releases an expired edit lock (status back to submitted). Returns true when something changed. */
    public function releaseExpiredLock(Submission $submission): bool
    {
        if ($submission->getStatus() !== Workflow::STATUS_SUPERVISOR_EDITING) {
            return false;
        }
        $expires = $submission->getData('edit_lock_expires_at');
        $expiresTs = $expires ? (int) $this->dateTime->gmtTimestamp($expires) : null;
        $holder = (int) $submission->getData('editing_supervisor_id');
        $holderValid = $holder > 0
            && $this->supervisorProvider->isSupervisorForWebsite($holder, $submission->getWebsiteId());
        if ($holderValid && !$this->accessRules->isLockExpired($expiresTs, (int) $this->dateTime->gmtTimestamp())) {
            return false;
        }
        try {
            $this->apply(
                $submission,
                Workflow::ACTION_LOCK_EXPIRED,
                Workflow::ROLE_SYSTEM,
                0,
                null,
                function (Submission $s): array {
                    $s->setData('editing_supervisor_id', null);
                    $s->setData('edit_lock_expires_at', null);
                    return [];
                }
            );
        } catch (ConcurrentModificationException) {
            return false;
        }
        return true;
    }

    // ---------------------------------------------------------------- loading with permission checks

    public function getSubmitterSubmission(int $customerId, int $projectId): Submission
    {
        $this->projectManager->getOwnedProject($projectId, $customerId);
        $submission = $this->repository->getByProjectId($projectId);
        if ($submission === null || !$this->accessRules->canActAsSubmitter($customerId, $submission->getCustomerId())) {
            throw new LocalizedException(__('This design has not been submitted for approval.'));
        }
        $this->releaseExpiredLock($submission);
        return $submission;
    }

    public function getSupervisorSubmission(int $supervisorId, int $submissionId, string $action): Submission
    {
        $submission = $this->repository->getById($submissionId);
        if (!$this->canSupervise($supervisorId, $submission, $action)) {
            throw new AuthorizationException(__('You are not allowed to review this design.'));
        }
        $this->releaseExpiredLock($submission);
        if ($action !== Workflow::ACTION_COMMENT && $action !== Workflow::ACTION_CANCEL_EDIT
            && $action !== Workflow::ACTION_SAVE_EDIT) {
            $this->assertNotLockedByOther($submission, $supervisorId, __(
                '%1 is editing this design right now.',
                $this->contactLabel((int) $submission->getData('editing_supervisor_id'))
            ));
        }
        return $submission;
    }

    public function canSupervise(int $supervisorId, Submission $submission, string $action = Workflow::ACTION_COMMENT): bool
    {
        $websiteId = (int) $this->storeManager->getWebsite()->getId();
        return $this->accessRules->canActAsSupervisor(
            $supervisorId,
            $this->supervisorProvider->isSupervisorForWebsite($supervisorId, $websiteId),
            $websiteId,
            $submission->getWebsiteId(),
            $submission->getCustomerId(),
            $this->approvalConfig->isEnabled($submission->getStoreId()),
            $action
        );
    }

    /** @return Event[] */
    public function getEvents(Submission $submission): array
    {
        return $this->repository->getEvents((int) $submission->getId());
    }

    // ---------------------------------------------------------------- internals

    /**
     * @param callable(Submission):array|null $mutate changes the submission (and project); returns event extras
     */
    private function apply(
        Submission $submission,
        string $action,
        string $role,
        int $actorId,
        ?string $message,
        ?callable $mutate = null,
        ?Project $project = null
    ): Submission {
        $message = $message !== null ? trim($message) : null;
        if ($message !== null && mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            throw new LocalizedException(__('Messages can be at most %1 characters long.', self::MAX_MESSAGE_LENGTH));
        }
        $previous = $submission->getStatus();
        if ($this->workflow->requiresMessage($action) && trim((string) $message) === ''
            && $this->workflow->isAllowed($previous, $action, $role)) {
            throw new LocalizedException(__('Please enter a message.'));
        }
        try {
            $next = $this->workflow->transition($previous, $action, $role, $message);
        } catch (InvalidTransitionException) {
            throw new LocalizedException($this->transitionErrorMessage($previous));
        }

        $connection = $this->repository->getConnection();
        $connection->beginTransaction();
        try {
            $extras = $mutate ? $mutate($submission) : [];
            if ($role === Workflow::ROLE_SUPERVISOR) {
                $submission->setData('last_supervisor_id', $actorId);
            }
            $submission->setData('status', $next);
            $this->repository->save($submission);
            if ($project !== null && $project->hasDataChanges()) {
                $this->projectResource->save($project);
            }
            $this->repository->addEvent([
                'submission_id'     => (int) $submission->getId(),
                'actor_customer_id' => $actorId > 0 ? $actorId : null,
                'actor_name'        => $actorId > 0 ? $this->contactLabel($actorId) : null,
                'actor_role'        => $role,
                'type'              => $action,
                'status_after'      => $next,
                'message'           => $message !== '' ? $message : null,
                'save_token'        => $extras['save_token'] ?? null,
                'thumbnail_url'     => $extras['thumbnail_url'] ?? null,
            ]);
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        $this->eventManager->dispatch(self::EVENT_TRANSITION_AFTER, [
            'submission'      => $submission,
            'action'          => $action,
            'role'            => $role,
            'actor_id'        => $actorId,
            'message'         => $message,
            'previous_status' => $previous,
        ]);
        return $submission;
    }

    private function copyDesignToProject(Project $project, string $token, string $thumbnailUrl): void
    {
        if ($token === '') {
            return;
        }
        $project->setData('save_token', $token);
        if ($thumbnailUrl !== '') {
            $project->setData('thumbnail_url', $thumbnailUrl);
        }
        $project->setData('updated_at', $this->dateTime->gmtDate());
    }

    /** SUP-16: a retracted design gets the normal project retention again. */
    private function restartExpiry(Project $project): void
    {
        $storeId = (int) $project->getData('store_id');
        if ($project->getData('ordered_at') !== null || !$this->projectConfig->isEnabled($storeId)) {
            return;
        }
        $project->setData('expires_at', $this->dateTime->gmtDate(
            null,
            (int) $this->dateTime->gmtTimestamp() + $this->projectConfig->getRetentionDays($storeId) * 86400
        ));
    }

    /** SUP-16: a design whose product is gone (or no longer a Printess product) cannot be approved. */
    private function assertProductAvailable(Submission $submission): void
    {
        $productId = (int) $submission->getData('product_id');
        try {
            $product = $this->productRepository->getById($productId, false, $submission->getStoreId());
            if (trim((string) $product->getData('printess_template')) === '') {
                throw new \RuntimeException('not a Printess product');
            }
        } catch (\Throwable) {
            throw new LocalizedException(__(
                'The product of this design is no longer available, so the design cannot be approved.'
            ));
        }
    }

    private function lockExpiry(Submission $submission): string
    {
        return $this->dateTime->gmtDate(
            null,
            (int) $this->dateTime->gmtTimestamp() + $this->approvalConfig->getEditLockSeconds($submission->getStoreId())
        );
    }

    private function assertOwnLock(Submission $submission, int $supervisorId): void
    {
        if ($submission->getStatus() !== Workflow::STATUS_SUPERVISOR_EDITING
            || (int) $submission->getData('editing_supervisor_id') !== $supervisorId) {
            throw new LocalizedException(__(
                'Your editing session for this design has ended (it was released or taken over). Reload the page.'
            ));
        }
    }

    private function assertNotLockedByOther(Submission $submission, int $actorId, \Magento\Framework\Phrase $message): void
    {
        if ($submission->getStatus() !== Workflow::STATUS_SUPERVISOR_EDITING) {
            return;
        }
        $expires = $submission->getData('edit_lock_expires_at');
        if ($this->accessRules->isLockedByOther(
            (int) $submission->getData('editing_supervisor_id') ?: null,
            $expires ? (int) $this->dateTime->gmtTimestamp($expires) : null,
            $actorId,
            (int) $this->dateTime->gmtTimestamp()
        )) {
            throw new LocalizedException($message);
        }
    }

    private function transitionErrorMessage(string $status): \Magento\Framework\Phrase
    {
        return match ($status) {
            Workflow::STATUS_RETRACTED          => __('This design was retracted by the customer.'),
            Workflow::STATUS_APPROVED           => __('This design has already been approved.'),
            Workflow::STATUS_ORDERED            => __('This design has already been ordered.'),
            Workflow::STATUS_SUPERVISOR_EDITING => __('A supervisor is editing this design right now.'),
            Workflow::STATUS_NEEDS_REVISION     => __('Changes were requested; the design is waiting for the customer.'),
            Workflow::STATUS_AWAITING_ACCEPTANCE => __('The design is waiting for the customer to accept the changes.'),
            default => __('This action is not possible in the design\'s current state. Reload the page.'),
        };
    }

    private function contactLabel(int $customerId): string
    {
        $contact = $this->supervisorProvider->getContact($customerId);
        if ($contact === null) {
            return (string) __('A supervisor');
        }
        return $contact['name'] !== '' ? $contact['name'] : $contact['email'];
    }
}
