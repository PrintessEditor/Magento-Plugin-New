<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Project;

use Printess\PrintessEditor\Model\ProjectManager;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Printess\PrintessEditor\Model\Project\EditorConfigBuilder;

class Open extends Action implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly Session $customerSession,
        private readonly ProjectManager $projectManager,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly DateTime $dateTime,
        private readonly EditorConfigBuilder $editorConfigBuilder,
        private readonly \Printess\PrintessEditor\Model\Approval\ApprovalGate $approvalGate,
        private readonly \Printess\PrintessEditor\Model\Approval\SubmissionRepository $submissionRepository,
        private readonly \Printess\PrintessEditor\Model\Approval\Workflow $approvalWorkflow
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        if (!$this->customerSession->isLoggedIn()) {
            return $result->setHttpResponseCode(401)->setData([
                'success' => false,
                'message' => __('You must be signed in to continue editing a Printess project.')
            ]);
        }

        try {
            $project = $this->projectManager->getOwnedProject(
                (int) $this->getRequest()->getParam('project_id'),
                (int) $this->customerSession->getCustomerId()
            );

            if ($this->isExpired($project)) {
                return $result->setData([
                    'success' => false,
                    'message' => __('This saved project has expired.')
                ]);
            }

            if (!$project->getData('product_id')) {
                return $result->setData([
                    'success' => false,
                    'message' => __('This project has no associated product and cannot be re-opened from here. Please visit the product page.')
                ]);
            }

            $product = $this->productRepository->getById((int) $project->getData('product_id'));
            if (trim((string) $product->getData('printess_template')) === '') {
                return $result->setData([
                    'success' => false,
                    'message' => __('This product does not support Printess projects.')
                ]);
            }

            $config = $this->editorConfigBuilder->build(
                $project,
                $product,
                (int) $this->customerSession->getCustomerId()
            );
            $config = $this->addApprovalConfig($config, $project);

            return $result->setData([
                'success' => true,
                'config' => $config
            ]);
        } catch (LocalizedException $exception) {
            return $result->setData([
                'success' => false,
                'message' => $exception->getMessage()
            ]);
        }
    }

    /**
     * SUP-07/08: with supervisor approval, "add to basket" submits the design; a locked design opens view-only.
     */
    private function addApprovalConfig(array $config, \Printess\PrintessEditor\Model\Project $project): array
    {
        $customerId = (int) $this->customerSession->getCustomerId();
        $storeId = (int) $project->getData('store_id');
        $config['approvalSubmit'] = $this->approvalGate->needsApproval($customerId, $storeId)
            ? ['submitUrl' => $this->_url->getUrl('printess/approval/submit')]
            : null;
        $submission = $this->approvalGate->isEnabled($storeId)
            ? $this->submissionRepository->getByProjectId((int) $project->getId())
            : null;
        if ($submission !== null) {
            if (!$this->approvalWorkflow->isEditableBySubmitter($submission->getStatus())) {
                $config['approval'] = ['mode' => 'view'];
            }
            $config['presetSelections'] = $submission->getBuyRequest();
        }
        return $config;
    }

    private function isExpired(\Printess\PrintessEditor\Model\Project $project): bool
    {
        $expiresAt = (string) $project->getData('expires_at');
        if ($expiresAt === '') {
            return false;
        }

        try {
            return (new \DateTimeImmutable($expiresAt, new \DateTimeZone('UTC')))->getTimestamp()
                <= $this->dateTime->gmtTimestamp();
        } catch (\Throwable) {
            return true;
        }
    }
}
