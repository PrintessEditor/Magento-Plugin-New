<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Project;

use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Printess\PrintessEditor\Model\ResourceModel\Project\CollectionFactory;

class ForProduct extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly Session $customerSession,
        private readonly CollectionFactory $collectionFactory,
        private readonly DateTime $dateTime
    ) {
        parent::__construct($context);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        if (!$this->customerSession->isLoggedIn()) {
            return $result->setHttpResponseCode(401)->setData(['success' => false, 'projects' => []]);
        }

        $customerId = (int) $this->customerSession->getCustomerId();
        $productId  = (int) $this->getRequest()->getParam('product_id');
        $now        = $this->dateTime->gmtDate();

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('customer_id', $customerId);

        if ($productId > 0) {
            $collection->addFieldToFilter('product_id', $productId);
        }

        $collection->addFieldToFilter(
            'expires_at',
            [['null' => true], ['gteq' => $now]]
        );
        $collection->setOrder('updated_at', 'DESC');
        $collection->setPageSize(50);

        $projects = [];
        foreach ($collection as $project) {
            $projects[] = [
                'id'           => (int) $project->getId(),
                'name'         => (string) $project->getData('name'),
                'saveToken'    => (string) $project->getData('save_token'),
                'thumbnailUrl' => (string) ($project->getData('thumbnail_url') ?? ''),
                'updatedAt'    => (string) ($project->getData('updated_at') ?? ''),
            ];
        }

        return $result->setData(['success' => true, 'projects' => $projects]);
    }
}
