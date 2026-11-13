<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Approval;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * SUP-08/SUP-12: submitter actions on one of their own projects.
 *
 * POST: form_key, project_id, do = resubmit|retract|accept|revise|reply|comment, message?
 * Plain form posts redirect back to the approval detail page; XHR posts get JSON.
 */
class Act extends AbstractApprovalAction implements HttpPostActionInterface
{
    public function execute()
    {
        $projectId = (int) $this->getRequest()->getParam('project_id');
        $do = (string) $this->getRequest()->getParam('do');
        $run = function () use ($projectId, $do): array {
            $customerId = $this->customerId();
            $message = $this->message();
            switch ($do) {
                case 'resubmit':
                    $this->approvalService->submit($customerId, $projectId, $message, null);
                    $done = __('Your design was submitted for approval again.');
                    break;
                case 'retract':
                    $this->approvalService->retract($customerId, $projectId);
                    $done = __('Your design was retracted. You can edit it again.');
                    break;
                case 'accept':
                    $this->approvalService->accept($customerId, $projectId);
                    $done = __('You accepted the supervisor\'s changes. The design is approved and can be ordered.');
                    break;
                case 'revise':
                    $this->approvalService->revise($customerId, $projectId, $message);
                    $done = __('You can now edit the design. Submit it again when you are done.');
                    break;
                case 'reply':
                    $this->approvalService->reply($customerId, $projectId, (string) $message);
                    $done = __('Your reply was sent. The design is back with the supervisors.');
                    break;
                case 'comment':
                    $this->approvalService->commentAsSubmitter($customerId, $projectId, (string) $message);
                    $done = __('Your comment was added.');
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
        return $redirect->setPath('printess/approval/project', ['id' => $projectId]);
    }
}
