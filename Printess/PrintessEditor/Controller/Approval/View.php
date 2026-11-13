<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Approval;

use Magento\Framework\Exception\LocalizedException;
use Printess\PrintessEditor\Model\Approval\Workflow;

/** SUP-09/10/11: one submission as a supervisor sees it. */
class View extends AbstractPage
{
    public function execute()
    {
        if (!$this->isSupervisorHere()) {
            return $this->notFound();
        }
        try {
            $submission = $this->approvalService->getSupervisorSubmission(
                (int) $this->customerSession->getCustomerId(),
                (int) $this->getRequest()->getParam('id'),
                Workflow::ACTION_COMMENT
            );
        } catch (LocalizedException) {
            return $this->notFound();
        }
        if ($submission->getStatus() === Workflow::STATUS_RETRACTED) {
            $this->messageManager->addNoticeMessage(__('This design was retracted by the customer.'));
            return $this->resultRedirectFactory->create()->setPath('printess/approval/index');
        }
        $project = $this->projectFactory->create();
        $this->projectResource->load($project, $submission->getProjectId());

        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set(__('Review: %1', (string) $project->getData('name')));
        $block = $page->getLayout()->getBlock('printess.approval.detail');
        if ($block) {
            $block->setData([
                'submission'    => $submission,
                'project'       => $project,
                'events'        => $this->approvalService->getEvents($submission),
                'role'          => Workflow::ROLE_SUPERVISOR,
                'viewer_id'     => (int) $this->customerSession->getCustomerId(),
                'option_labels' => $this->optionLabels(
                    $submission->getData('product_id') ? (int) $submission->getData('product_id') : null,
                    $submission->getStoreId()
                ),
            ]);
        }
        return $page;
    }
}
