<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Approval;

use Magento\Framework\Exception\LocalizedException;
use Printess\PrintessEditor\Model\Approval\Workflow;

/** SUP-08/12: the submitter's view of one of their designs in the approval process. */
class Project extends AbstractPage
{
    public function execute()
    {
        $customerId = (int) $this->customerSession->getCustomerId();
        try {
            $project = $this->projectManager->getOwnedProject((int) $this->getRequest()->getParam('id'), $customerId);
        } catch (LocalizedException) {
            return $this->notFound();
        }
        $submission = $this->submissionRepository->getByProjectId((int) $project->getId());
        if ($submission !== null) {
            $this->approvalService->releaseExpiredLock($submission);
        }
        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set((string) $project->getData('name'));
        $block = $page->getLayout()->getBlock('printess.approval.detail');
        if ($block) {
            $block->setData([
                'submission'    => $submission,
                'project'       => $project,
                'events'        => $submission ? $this->approvalService->getEvents($submission) : [],
                'role'          => Workflow::ROLE_SUBMITTER,
                'viewer_id'     => $customerId,
                'option_labels' => $this->optionLabels(
                    $project->getData('product_id') ? (int) $project->getData('product_id') : null,
                    (int) $project->getData('store_id')
                ),
            ]);
        }
        return $page;
    }
}
