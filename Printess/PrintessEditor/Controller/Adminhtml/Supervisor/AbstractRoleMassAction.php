<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Adminhtml\Supervisor;

use Magento\Backend\App\Action\Context;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Controller\Adminhtml\Index\AbstractMassAction;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory;
use Magento\Eav\Model\Entity\Collection\AbstractCollection;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Ui\Component\MassAction\Filter;
use Printess\PrintessEditor\Model\Approval\SupervisorProvider;

/** SUP-04: sets printess_is_supervisor on the customers selected in the customer grid. */
abstract class AbstractRoleMassAction extends AbstractMassAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magento_Customer::manage';

    public function __construct(
        Context $context,
        Filter $filter,
        CollectionFactory $collectionFactory,
        private readonly CustomerRepositoryInterface $customerRepository
    ) {
        parent::__construct($context, $filter, $collectionFactory);
    }

    abstract protected function getRoleValue(): int;

    protected function massAction(AbstractCollection $collection)
    {
        $updated = 0;
        foreach ($collection->getAllIds() as $customerId) {
            $customer = $this->customerRepository->getById((int) $customerId);
            $current = $customer->getCustomAttribute(SupervisorProvider::ATTRIBUTE_CODE);
            if ($current !== null && (int) $current->getValue() === $this->getRoleValue()) {
                continue;
            }
            $customer->setCustomAttribute(SupervisorProvider::ATTRIBUTE_CODE, $this->getRoleValue());
            $this->customerRepository->save($customer);
            $updated++;
        }
        $this->messageManager->addSuccessMessage(__('A total of %1 customer(s) were updated.', $updated));
        $resultRedirect = $this->resultRedirectFactory->create();
        return $resultRedirect->setPath('customer/index/index');
    }
}
