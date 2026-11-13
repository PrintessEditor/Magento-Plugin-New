<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Approval;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Printess\PrintessEditor\Model\Approval\ApprovalService;
use Printess\PrintessEditor\Model\Approval\ReviewEditorConfig;
use Printess\PrintessEditor\Model\Approval\Workflow;
use Psr\Log\LoggerInterface;

/**
 * SUP-11: supervisor opens a submitted design in the editor.
 * POST: form_key, submission_id, mode = edit (takes the edit lock) | view (read only)
 */
class StartEdit extends AbstractApprovalAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        CustomerSession $customerSession,
        JsonFactory $jsonFactory,
        ApprovalService $approvalService,
        LoggerInterface $logger,
        private readonly ReviewEditorConfig $reviewEditorConfig
    ) {
        parent::__construct($context, $customerSession, $jsonFactory, $approvalService, $logger);
    }

    public function execute()
    {
        return $this->jsonCall(function (): array {
            $submissionId = (int) $this->getRequest()->getParam('submission_id');
            $supervisorId = $this->customerId();
            if ($this->getRequest()->getParam('mode') === 'edit') {
                $submission = $this->approvalService->startEdit($supervisorId, $submissionId);
                return ['config' => $this->reviewEditorConfig->build($submission, 'edit', $supervisorId)];
            }
            $submission = $this->approvalService->getSupervisorSubmission(
                $supervisorId,
                $submissionId,
                Workflow::ACTION_COMMENT
            );
            $token = $this->getRequest()->getParam('version') === 'supervisor'
                && $submission->getData('supervisor_save_token')
                ? (string) $submission->getData('supervisor_save_token') : null;
            return ['config' => $this->reviewEditorConfig->build($submission, 'view', $supervisorId, $token)];
        });
    }
}
