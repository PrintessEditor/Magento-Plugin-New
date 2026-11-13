<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Approval;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Store\Model\StoreManagerInterface;
use Printess\PrintessEditor\Model\Approval\ApprovalConfig;
use Printess\PrintessEditor\Model\Approval\ApprovalService;
use Printess\PrintessEditor\Model\Approval\SubmissionRepository;
use Printess\PrintessEditor\Model\Approval\SupervisorProvider;
use Printess\PrintessEditor\Model\Project\EditorConfigBuilder;
use Printess\PrintessEditor\Model\ProjectManager;

/**
 * Base for the storefront approval pages (customer account area, never full-page cached).
 */
abstract class AbstractPage extends Action implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        protected readonly CustomerSession $customerSession,
        protected readonly PageFactory $pageFactory,
        protected readonly ApprovalService $approvalService,
        protected readonly ApprovalConfig $approvalConfig,
        protected readonly SupervisorProvider $supervisorProvider,
        protected readonly SubmissionRepository $submissionRepository,
        protected readonly ProjectManager $projectManager,
        protected readonly ProductRepositoryInterface $productRepository,
        protected readonly EditorConfigBuilder $editorConfigBuilder,
        protected readonly StoreManagerInterface $storeManager,
        protected readonly \Printess\PrintessEditor\Model\ProjectFactory $projectFactory,
        protected readonly \Printess\PrintessEditor\Model\ResourceModel\Project $projectResource
    ) {
        parent::__construct($context);
    }

    public function dispatch(\Magento\Framework\App\RequestInterface $request)
    {
        if (!$this->customerSession->authenticate()) {
            $this->_actionFlag->set('', self::FLAG_NO_DISPATCH, true);
        }
        return parent::dispatch($request);
    }

    protected function isSupervisorHere(): bool
    {
        return $this->approvalConfig->isEnabled()
            && $this->supervisorProvider->isSupervisorForWebsite(
                (int) $this->customerSession->getCustomerId(),
                (int) $this->storeManager->getWebsite()->getId()
            );
    }

    protected function notFound()
    {
        $this->_forward('noroute');
        return null;
    }

    protected function optionLabels(?int $productId, int $storeId): array
    {
        if (!$productId) {
            return [];
        }
        try {
            return $this->editorConfigBuilder->buildOptionLabels(
                $this->productRepository->getById($productId, false, $storeId)
            );
        } catch (\Throwable) {
            return [];
        }
    }
}
