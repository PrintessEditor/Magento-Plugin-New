<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Project;

use Magento\Customer\Controller\AbstractAccount;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

class Index extends AbstractAccount implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set(__('My Projects'));

        if ($navigation = $page->getLayout()->getBlock('customer_account_navigation')) {
            $navigation->setActive('printess/project');
        }

        return $page;
    }
}
