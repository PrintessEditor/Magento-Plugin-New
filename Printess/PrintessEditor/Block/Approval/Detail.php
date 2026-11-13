<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Block\Approval;

use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Printess\PrintessEditor\Model\Approval\ApprovalGate;
use Printess\PrintessEditor\Model\Approval\Event;
use Printess\PrintessEditor\Model\Approval\StatusLabels;
use Printess\PrintessEditor\Model\Approval\Submission;
use Printess\PrintessEditor\Model\Approval\SupervisorProvider;
use Printess\PrintessEditor\Model\Approval\Workflow;
use Printess\PrintessEditor\Model\Project;

/**
 * SUP-08/09/10/11/12: one design in the approval process with its history, comment thread and the actions the
 * viewer may take. Used for the submitter (printess/approval/project) and supervisors (printess/approval/view).
 *
 * Data set by the controller: submission (Submission|null), project (Project), events (Event[]), role, viewer_id.
 */
class Detail extends Template
{
    public function __construct(
        Context $context,
        private readonly Workflow $workflow,
        private readonly StatusLabels $statusLabels,
        private readonly SupervisorProvider $supervisorProvider,
        private readonly ApprovalGate $approvalGate,
        private readonly FormKey $formKey,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getSubmission(): ?Submission
    {
        $s = $this->getData('submission');
        return $s instanceof Submission ? $s : null;
    }

    public function getProject(): Project
    {
        return $this->getData('project');
    }

    /** @return Event[] */
    public function getEvents(): array
    {
        return (array) $this->getData('events');
    }

    public function getRole(): string
    {
        return (string) $this->getData('role');
    }

    public function isSupervisorView(): bool
    {
        return $this->getRole() === Workflow::ROLE_SUPERVISOR;
    }

    public function getStatus(): string
    {
        return $this->getSubmission()?->getStatus() ?? Workflow::STATUS_NONE;
    }

    public function getStatusLabel(): string
    {
        return $this->statusLabels->status($this->getStatus(), $this->getRole());
    }

    public function getStatusTone(): string
    {
        return $this->statusLabels->tone($this->getStatus());
    }

    public function getEventLabel(string $type): string
    {
        return $this->statusLabels->event($type);
    }

    public function can(string $action): bool
    {
        $submission = $this->getSubmission();
        if ($this->isSupervisorView()) {
            if ($submission === null || !$this->workflow->isAllowed($this->getStatus(), $action, Workflow::ROLE_SUPERVISOR)) {
                return false;
            }
            if ((int) $this->getData('viewer_id') === $submission->getCustomerId() && $action !== Workflow::ACTION_COMMENT) {
                return false; // nobody approves their own design
            }
            return !$this->isLockedByOther();
        }
        if ($action === Workflow::ACTION_SUBMIT
            && ($submission === null || $submission->getData('buy_request_json') === null)) {
            return false; // never submitted from the editor: options unknown, submit from the editor instead
        }
        return $this->workflow->isAllowed($this->getStatus(), $action, Workflow::ROLE_SUBMITTER);
    }

    /** Submitter can continue in the editor (and submit from there). */
    public function canEditDesign(): bool
    {
        return !$this->isSupervisorView() && $this->workflow->isEditableBySubmitter($this->getStatus());
    }

    public function isOrderable(): bool
    {
        return !$this->isSupervisorView() && $this->workflow->isOrderable($this->getStatus());
    }

    public function isEditingByViewer(): bool
    {
        $s = $this->getSubmission();
        return $s !== null && $this->getStatus() === Workflow::STATUS_SUPERVISOR_EDITING
            && (int) $s->getData('editing_supervisor_id') === (int) $this->getData('viewer_id');
    }

    public function isLockedByOther(): bool
    {
        $s = $this->getSubmission();
        return $s !== null && $this->getStatus() === Workflow::STATUS_SUPERVISOR_EDITING
            && (int) $s->getData('editing_supervisor_id') !== (int) $this->getData('viewer_id');
    }

    public function getEditorName(): string
    {
        $contact = $this->supervisorProvider->getContact((int) $this->getSubmission()?->getData('editing_supervisor_id'));
        return $contact ? ($contact['name'] ?: $contact['email']) : (string) __('A supervisor');
    }

    public function getSubmitterLabel(): string
    {
        $contact = $this->supervisorProvider->getContact((int) $this->getSubmission()?->getCustomerId());
        return $contact ? trim($contact['name'] . ' <' . $contact['email'] . '>') : '';
    }

    public function getAssignedSupervisorLabel(): string
    {
        $id = (int) $this->getSubmission()?->getData('assigned_supervisor_id');
        $contact = $id ? $this->supervisorProvider->getContact($id) : null;
        return $contact ? ($contact['name'] ?: $contact['email']) : (string) __('All supervisors');
    }

    /** @return array<string,string> human readable product options of the submission */
    public function getOptionsSummary(): array
    {
        $buy = $this->getSubmission()?->getBuyRequest() ?? [];
        $out = [];
        if (isset($buy['qty'])) {
            $out[(string) __('Quantity')] = (string) (float) $buy['qty'];
        }
        $labels = (array) $this->getData('option_labels');
        foreach (['super_attribute', 'options'] as $group) {
            foreach ((array) ($buy[$group] ?? []) as $id => $value) {
                $key = $group . ':' . $id;
                $label = $labels[$key]['label'] ?? null;
                if ($label !== null) {
                    $out[$label] = $labels[$key]['values'][(string) $value] ?? (string) $value;
                }
            }
        }
        return $out;
    }

    public function getSupervisorThumbnail(): string
    {
        return (string) $this->getSubmission()?->getData('supervisor_thumbnail_url');
    }

    public function getPostUrl(): string
    {
        return $this->isSupervisorView()
            ? $this->getUrl('printess/approval/review')
            : $this->getUrl('printess/approval/act');
    }

    public function getBackUrl(): string
    {
        return $this->isSupervisorView()
            ? $this->getUrl('printess/approval/index')
            : $this->getUrl('printess/project');
    }

    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }

    public function formatDateTime(?string $value): string
    {
        return $value ? $this->formatDate($value, \IntlDateFormatter::MEDIUM, true) : '';
    }

    public function getJsConfig(): array
    {
        $submission = $this->getSubmission();
        return [
            'role'          => $this->getRole(),
            'submissionId'  => $submission ? (int) $submission->getId() : null,
            'projectId'     => (int) $this->getProject()->getId(),
            'startEditUrl'  => $this->getUrl('printess/approval/startEdit'),
            'openUrl'       => $this->getUrl('printess/project/open'),
            'addToCartUrl'  => $this->getUrl('printess/approval/addToCart'),
            'reviewUrl'     => $this->getUrl('printess/approval/review'),
            'formKey'       => $this->getFormKey(),
            'editingByMe'   => $this->isEditingByViewer(),
        ];
    }
}
