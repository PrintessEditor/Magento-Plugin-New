<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Approval;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Printess\PrintessEditor\Model\Approval\ApprovalGate;
use Printess\PrintessEditor\Model\Approval\ApprovalService;
use Printess\PrintessEditor\Model\ProjectManager;
use Psr\Log\LoggerInterface;

/**
 * SUP-07: "Submit for approval" from the editor. Saves the design as a project (new or the one being edited) and
 * submits it in one step; nothing is added to the cart. Customers who don't need approval get `direct: true` and the
 * editor continues with the normal add-to-cart.
 *
 * POST: form_key, save_token, thumbnail_url, product_id, project_id?, project_name?, message?, buy_request (JSON)
 */
class Submit extends AbstractApprovalAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        CustomerSession $customerSession,
        JsonFactory $jsonFactory,
        ApprovalService $approvalService,
        LoggerInterface $logger,
        private readonly ProjectManager $projectManager,
        private readonly ApprovalGate $approvalGate
    ) {
        parent::__construct($context, $customerSession, $jsonFactory, $approvalService, $logger);
    }

    public function execute()
    {
        return $this->jsonCall(function (): array {
            $request = $this->getRequest();
            $customerId = $this->customerId();
            if (!$this->approvalGate->needsApproval($customerId)) {
                return ['direct' => true];
            }
            // check=1: the editor only asks whether this customer needs approval (pages may be full-page cached)
            if ($request->getParam('check')) {
                return ['direct' => false];
            }
            $projectId = (int) $request->getParam('project_id') ?: null;
            $name = trim((string) $request->getParam('project_name', '')) ?: null;
            $project = $this->projectManager->save(
                $customerId,
                (int) $request->getParam('product_id'),
                (string) $request->getParam('save_token', ''),
                trim((string) $request->getParam('thumbnail_url', '')) ?: null,
                $projectId,
                $name
            );
            $submission = $this->approvalService->submit(
                $customerId,
                (int) $project->getId(),
                $this->message(),
                $this->buyRequest()
            );
            return [
                'project_id'   => (int) $project->getId(),
                'status'       => $submission->getStatus(),
                'projects_url' => $this->_url->getUrl('printess/project'),
                'detail_url'   => $this->_url->getUrl('printess/approval/project', ['id' => (int) $project->getId()]),
                'message'      => (string) __('Your design was submitted for approval. You will get an email when a supervisor has reviewed it.'),
            ];
        });
    }
}
