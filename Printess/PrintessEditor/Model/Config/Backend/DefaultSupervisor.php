<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Printess\PrintessEditor\Model\Approval\SupervisorProvider;

/**
 * "Default Order Supervisor" must be a customer with the Supervisor role who can act in the website of the scope
 * being saved.
 */
class DefaultSupervisor extends Value
{
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly SupervisorProvider $supervisorProvider,
        private readonly StoreManagerInterface $storeManager,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    public function beforeSave()
    {
        $customerId = (int) $this->getValue();
        if ($customerId <= 0) {
            $this->setValue('');
            return parent::beforeSave();
        }
        if (!$this->supervisorProvider->isSupervisor($customerId)) {
            throw new LocalizedException(__(
                'The Default Order Supervisor must be a customer with the Printess Supervisor role.'
            ));
        }
        $websiteId = $this->getScopeWebsiteId();
        if ($websiteId !== null && !$this->supervisorProvider->isSupervisorForWebsite($customerId, $websiteId)) {
            throw new LocalizedException(__(
                'The selected Default Order Supervisor belongs to another website. Choose a supervisor of this website.'
            ));
        }
        return parent::beforeSave();
    }

    private function getScopeWebsiteId(): ?int
    {
        $scope = (string) $this->getScope();
        $scopeId = (int) $this->getScopeId();
        if ($scope === ScopeInterface::SCOPE_WEBSITES || $scope === ScopeInterface::SCOPE_WEBSITE) {
            return $scopeId;
        }
        if ($scope === ScopeInterface::SCOPE_STORES || $scope === ScopeInterface::SCOPE_STORE) {
            return (int) $this->storeManager->getStore($scopeId)->getWebsiteId();
        }
        return null;
    }
}
