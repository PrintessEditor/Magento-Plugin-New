<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Approval;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * SUP-10: supervisor decisions on a submission.
 *
 * POST: form_key, submission_id, do = approve|request_changes|comment|cancel_edit, message?
 * Plain form posts redirect back to the review page; XHR posts get JSON.
 */
class Review extends AbstractApprovalAction implements HttpPostActionInterface
{
    public function execute()
    {
        $submissionId = (int) $this->getRequest()->getParam('submission_id');
        $do = (string) $this->getRequest()->getParam('do');
        $run = function () use ($submissionId, $do): array {
            $supervisorId = $this->customerId();
            $message = $this->message();
            switch ($do) {
                case 'approve':
                    $this->approvalService->approve($supervisorId, $submissionId, $message);
                    $done = __('The design was approved. The customer can order it now.');
                    break;
                case 'request_changes':
                    $this->approvalService->requestChanges($supervisorId, $submissionId, (string) $message);
                    $done = __('Your feedback was sent. The customer can edit the design again.');
                    break;
                case 'comment':
                    $this->approvalService->commentAsSupervisor($supervisorId, $submissionId, (string) $message);
                    $done = __('Your comment was added.');
                    break;
                case 'cancel_edit':
                    $this->approvalService->cancelEdit($supervisorId, $submissionId);
                    $done = __('Editing was cancelled. The design is back in the review queue.');
                    break;
                default:
                    throw new LocalizedException(__('Unknown action.'));
            }
            return ['message' => (string) $done];
        };

        if ($this->getRequest()->isXmlHttpRequest()) {
            return $this->jsonCall($run);
        }
        $redirect = $this->resultRedirectFactory->create();
        if (!$this->customerSession->isLoggedIn()) {
            return $redirect->setPath('customer/account/login');
        }
        try {
            $this->messageManager->addSuccessMessage($run()['message']);
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Printess approval: ' . $e->getMessage(), ['exception' => $e]);
            $this->messageManager->addErrorMessage(__('Something went wrong. Please try again.'));
        }
        return $redirect->setPath('printess/approval/view', ['id' => $submissionId]);
    }
}
