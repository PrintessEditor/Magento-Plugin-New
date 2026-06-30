<?php
namespace Printess\PrintessEditor\Controller\Project;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Printess\PrintessEditor\Model\ResourceModel\SavedProject\Collection;
use Printess\PrintessEditor\Model\ResourceModel\SavedProject\CollectionFactory;

class ListAction implements HttpGetActionInterface
{
    public function __construct(
        private readonly JsonFactory        $jsonFactory,
        private readonly CustomerSession    $customerSession,
        private readonly CollectionFactory  $collectionFactory
    ) {}

    public function execute()
    {
        $result = $this->jsonFactory->create();

        if (!$this->customerSession->isLoggedIn()) {
            return $result->setData([])->setHttpResponseCode(401);
        }

        /** @var Collection $collection */
        $collection = $this->collectionFactory->create();
        $collection
            ->addFieldToFilter('customer_id', (int)$this->customerSession->getCustomerId())
            ->setOrder('created_at', 'DESC');

        $projects = [];
        foreach ($collection as $project) {
            $projects[] = [
                'id'         => (int)$project->getId(),
                'name'       => $project->getData('name'),
                'save_token' => $project->getData('save_token'),
                'created_at' => $project->getData('created_at'),
            ];
        }

        return $result->setData($projects);
    }
}
