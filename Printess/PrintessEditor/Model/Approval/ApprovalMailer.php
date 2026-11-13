<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Approval;

use Magento\Framework\App\Area;
use Magento\Framework\Escaper;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Printess\PrintessEditor\Model\ProjectFactory;
use Printess\PrintessEditor\Model\ResourceModel\Project as ProjectResource;
use Psr\Log\LoggerInterface;

/**
 * SUP-14: transactional emails for every hand-over between submitter and supervisors.
 * Recipients come from ApprovalNotificationRules; failures are logged and never undo the workflow action.
 */
class ApprovalMailer
{
    public function __construct(
        private readonly TransportBuilder $transportBuilder,
        private readonly ApprovalConfig $approvalConfig,
        private readonly SupervisorProvider $supervisorProvider,
        private readonly SupervisorResolver $supervisorResolver,
        private readonly ApprovalNotificationRules $rules,
        private readonly StatusLabels $statusLabels,
        private readonly StoreManagerInterface $storeManager,
        private readonly ProjectFactory $projectFactory,
        private readonly ProjectResource $projectResource,
        private readonly Escaper $escaper,
        private readonly LoggerInterface $logger
    ) {
    }

    public function notify(Submission $submission, string $action, string $role, int $actorId, ?string $message): void
    {
        $storeId = $submission->getStoreId();
        if (!$this->approvalConfig->isEnabled($storeId)) {
            return;
        }
        $plan = $this->rules->plan(
            $action,
            $role,
            $this->approvalConfig->notifyOnRetract($storeId)
        );
        if ($plan === null) {
            return;
        }
        [$notification, $audience] = $plan;

        $recipientIds = match ($audience) {
            ApprovalNotificationRules::TO_SUBMITTER => [$submission->getCustomerId()],
            ApprovalNotificationRules::TO_LAST_SUPERVISOR => $this->lastSupervisorOrAll($submission, $actorId),
            default => $this->supervisorResolver->getRecipients(
                $submission->getData('assigned_supervisor_id') ? (int) $submission->getData('assigned_supervisor_id') : null,
                $submission->getWebsiteId(),
                $submission->getCustomerId()
            ),
        };
        $recipientIds = array_values(array_unique(array_filter(
            $recipientIds,
            static fn (int $id): bool => $id > 0 && $id !== $actorId
        )));
        if ($recipientIds === []) {
            return;
        }

        $templateId = $this->approvalConfig->getEmailTemplate($notification, $storeId);
        if ($templateId === '') {
            return;
        }
        $project = $this->projectFactory->create();
        $this->projectResource->load($project, $submission->getProjectId());
        $store = $this->storeManager->getStore($storeId);
        $actor = $this->supervisorProvider->getContact($actorId);
        $submitter = $this->supervisorProvider->getContact($submission->getCustomerId());

        foreach ($recipientIds as $recipientId) {
            $recipient = $this->supervisorProvider->getContact($recipientId);
            if ($recipient === null || $recipient['email'] === '') {
                continue;
            }
            $toSubmitter = $recipientId === $submission->getCustomerId();
            $link = $toSubmitter
                ? $store->getUrl('printess/approval/project', ['id' => $submission->getProjectId(), '_nosid' => true])
                : $store->getUrl('printess/approval/view', ['id' => (int) $submission->getId(), '_nosid' => true]);
            try {
                $this->transportBuilder
                    ->setTemplateIdentifier($templateId)
                    ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
                    ->setTemplateVars([
                        'recipient_name' => $this->escaper->escapeHtml($recipient['name'] ?: $recipient['email']),
                        'actor_name'     => $this->escaper->escapeHtml($actor['name'] ?? (string) __('A supervisor')),
                        'submitter_name' => $this->escaper->escapeHtml($submitter['name'] ?? ''),
                        'project_name'   => $this->escaper->escapeHtml((string) $project->getData('name')),
                        'product_name'   => $this->escaper->escapeHtml((string) $project->getData('product_name')),
                        'message'        => $message !== null && $message !== ''
                            ? nl2br($this->escaper->escapeHtml($message)) : '',
                        'has_message'    => $message !== null && $message !== '',
                        'status_label'   => $this->escaper->escapeHtml($this->statusLabels->status(
                            $submission->getStatus(),
                            $toSubmitter ? Workflow::ROLE_SUBMITTER : Workflow::ROLE_SUPERVISOR
                        )),
                        'thumbnail_url'  => $this->escaper->escapeUrl((string) (
                            $notification === 'supervisor_changed' && $submission->getData('supervisor_thumbnail_url')
                                ? $submission->getData('supervisor_thumbnail_url')
                                : $project->getData('thumbnail_url')
                        )),
                        'link_url'       => $this->escaper->escapeUrl($link),
                        'store_name'     => $this->escaper->escapeHtml((string) $store->getFrontendName()),
                    ])
                    ->setFromByScope($this->approvalConfig->getEmailIdentity($storeId), $storeId)
                    ->addTo($recipient['email'], $recipient['name'])
                    ->getTransport()
                    ->sendMessage();
            } catch (\Throwable $e) {
                $this->logger->error('Printess approval: email could not be sent', [
                    'notification'  => $notification,
                    'submission_id' => (int) $submission->getId(),
                    'recipient_id'  => $recipientId,
                    'error'         => $e->getMessage(),
                ]);
            }
        }
    }

    /** @return int[] */
    private function lastSupervisorOrAll(Submission $submission, int $actorId): array
    {
        $last = (int) $submission->getData('last_supervisor_id');
        if ($last > 0 && $last !== $actorId
            && $this->supervisorProvider->isSupervisorForWebsite($last, $submission->getWebsiteId())) {
            return [$last];
        }
        return $this->supervisorResolver->getRecipients(
            $submission->getData('assigned_supervisor_id') ? (int) $submission->getData('assigned_supervisor_id') : null,
            $submission->getWebsiteId(),
            $submission->getCustomerId()
        );
    }
}
