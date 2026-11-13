<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Approval;

use Magento\Customer\Model\Config\Share as CustomerShare;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;

/**
 * Storefront customers flagged as Printess Supervisor (customer attribute printess_is_supervisor, set in the admin).
 * Supervisor status is website wide: with per-website customer accounts a supervisor belongs to their account's
 * website, with global accounts to every website.
 */
class SupervisorProvider
{
    public const ATTRIBUTE_CODE = 'printess_is_supervisor';

    /** @var array<int,array{is:bool,website:int,name:string,email:string}|null> */
    private array $cache = [];

    public function __construct(
        private readonly CustomerCollectionFactory $customerCollectionFactory,
        private readonly CustomerShare $customerShare
    ) {
    }

    public function isSupervisor(int $customerId): bool
    {
        $info = $this->load($customerId);
        return $info !== null && $info['is'];
    }

    /** Supervisor who may act in $websiteId (role + website, honoring the account sharing scope). */
    public function isSupervisorForWebsite(int $customerId, int $websiteId): bool
    {
        $info = $this->load($customerId);
        if ($info === null || !$info['is']) {
            return false;
        }
        return !$this->customerShare->isWebsiteScope() || $info['website'] === $websiteId;
    }

    /** @return array{name:string,email:string}|null */
    public function getContact(int $customerId): ?array
    {
        $info = $this->load($customerId);
        return $info === null ? null : ['name' => $info['name'], 'email' => $info['email']];
    }

    /**
     * @return array<int,array{id:int,name:string,email:string,website_id:int}>
     */
    public function getSupervisors(?int $websiteId = null): array
    {
        $collection = $this->customerCollectionFactory->create();
        $collection->addNameToSelect()
            ->addAttributeToSelect('email')
            ->addAttributeToFilter(self::ATTRIBUTE_CODE, 1)
            ->setOrder('lastname', 'ASC');
        if ($websiteId !== null && $this->customerShare->isWebsiteScope()) {
            $collection->addAttributeToFilter('website_id', $websiteId);
        }
        $out = [];
        foreach ($collection as $customer) {
            $out[(int) $customer->getId()] = [
                'id'         => (int) $customer->getId(),
                'name'       => trim((string) $customer->getName()),
                'email'      => (string) $customer->getEmail(),
                'website_id' => (int) $customer->getData('website_id'),
            ];
        }
        return $out;
    }

    public function reset(): void
    {
        $this->cache = [];
    }

    /** @return array{is:bool,website:int,name:string,email:string}|null */
    private function load(int $customerId): ?array
    {
        if ($customerId <= 0) {
            return null;
        }
        if (!array_key_exists($customerId, $this->cache)) {
            $collection = $this->customerCollectionFactory->create();
            $collection->addNameToSelect()
                ->addAttributeToSelect(['email', self::ATTRIBUTE_CODE])
                ->addAttributeToFilter('entity_id', $customerId)
                ->setPageSize(1);
            $customer = $collection->getFirstItem();
            $this->cache[$customerId] = $customer->getId() ? [
                'is'      => (int) $customer->getData(self::ATTRIBUTE_CODE) === 1,
                'website' => (int) $customer->getData('website_id'),
                'name'    => trim((string) $customer->getName()),
                'email'   => (string) $customer->getEmail(),
            ] : null;
        }
        return $this->cache[$customerId];
    }
}
