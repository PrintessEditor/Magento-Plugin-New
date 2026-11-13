<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Approval;

use Magento\Framework\App\Action\HttpPostActionInterface;

/**
 * SUP-11: supervisor saves the edited design and marks it approved (the submitter then has to accept it).
 * POST: form_key, submission_id, save_token, thumbnail_url, buy_request (JSON), message?
 */
class SaveEdit extends AbstractApprovalAction implements HttpPostActionInterface
{
    public function execute()
    {
        return $this->jsonCall(function (): array {
            $submissionId = (int) $this->getRequest()->getParam('submission_id');
            $this->approvalService->saveSupervisorEdit(
                $this->customerId(),
                $submissionId,
                (string) $this->getRequest()->getParam('save_token', ''),
                trim((string) $this->getRequest()->getParam('thumbnail_url', '')) ?: null,
                $this->buyRequest(),
                $this->message()
            );
            $this->messageManager->addSuccessMessage(__(
                'Your changes were saved and the design is marked as approved. The customer has been asked to accept them.'
            ));
            return ['redirect' => $this->_url->getUrl('printess/approval/view', ['id' => $submissionId])];
        });
    }
}
