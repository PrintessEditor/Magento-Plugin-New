<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Block\Adminhtml\Customer\Edit\Tab;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Config\Share as CustomerShare;
use Magento\Framework\AuthorizationInterface;
use Magento\Ui\Component\Layout\Tabs\TabInterface;
use Printess\PrintessEditor\Model\CustomerFields\FieldValue;
use Printess\PrintessEditor\Model\CustomerFields\Repository;

/**
 * RMC-02: "Printess" tab on Admin → Customers → Edit with the customer's RM_CUST per store view.
 * Only store views the customer can log into are listed (all of them with global accounts, otherwise the store views
 * of the customer's website). Saved with its own button via printess/customer/saveFields.
 */
class PrintessFields extends Template implements TabInterface
{
    protected $_template = 'Printess_PrintessEditor::customer/printess-fields.phtml';

    public function __construct(
        Context $context,
        private readonly Repository $repository,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly CustomerShare $customerShare,
        private readonly AuthorizationInterface $authorization,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getCustomerId(): int
    {
        return (int) $this->getRequest()->getParam('id');
    }

    public function getTabLabel()
    {
        return __('Printess');
    }

    public function getTabTitle()
    {
        return __('Printess');
    }

    public function canShowTab()
    {
        return $this->getCustomerId() > 0 && $this->authorization->isAllowed('Magento_Customer::manage');
    }

    public function isHidden()
    {
        return !$this->canShowTab();
    }

    public function getTabClass()
    {
        return '';
    }

    public function getTabUrl()
    {
        return '';
    }

    public function isAjaxLoaded()
    {
        return false;
    }

    /** @return string[] */
    public function getFields(): array
    {
        return FieldValue::FIELDS;
    }

    /**
     * @return list<array{website:string, stores:list<array{id:int,name:string,code:string,values:array<string,string>}>}>
     */
    public function getRows(): array
    {
        $customerId = $this->getCustomerId();
        $values = $this->repository->getAllForCustomer($customerId);
        $onlyWebsite = null;
        if ($this->customerShare->isWebsiteScope()) {
            try {
                $onlyWebsite = (int) $this->customerRepository->getById($customerId)->getWebsiteId();
            } catch (\Throwable) {
                $onlyWebsite = null;
            }
        }
        $rows = [];
        foreach ($this->_storeManager->getWebsites() as $website) {
            if ($onlyWebsite !== null && (int) $website->getId() !== $onlyWebsite) {
                continue;
            }
            $stores = [];
            foreach ($website->getStores() as $store) {
                $stores[] = [
                    'id'     => (int) $store->getId(),
                    'name'   => (string) $store->getName(),
                    'code'   => (string) $store->getCode(),
                    'values' => $values[(int) $store->getId()] ?? [],
                ];
            }
            if ($stores) {
                $rows[] = ['website' => (string) $website->getName(), 'stores' => $stores];
            }
        }
        return $rows;
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('printess/customer/saveFields');
    }

    public function getMaxLength(): int
    {
        return FieldValue::MAX_LENGTH;
    }
}
