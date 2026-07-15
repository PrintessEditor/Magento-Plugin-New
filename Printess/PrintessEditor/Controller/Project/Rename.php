<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Project;

use Printess\PrintessEditor\Model\ProjectManager;
use Magento\Customer\Controller\AbstractAccount;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;

class Rename extends AbstractAccount implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly Session $customerSession,
        private readonly ProjectManager $projectManager
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        try {
            $name = mb_substr(strip_tags(trim((string) $this->getRequest()->getParam('name'))), 0, 255);
            $this->projectManager->rename(
                (int) $this->getRequest()->getParam('project_id'),
                (int) $this->customerSession->getCustomerId(),
                $name
            );
            $this->messageManager->addSuccessMessage(__('The project name has been updated.'));
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        }

        return $this->resultRedirectFactory->create()->setPath('printess/project');
    }
}
