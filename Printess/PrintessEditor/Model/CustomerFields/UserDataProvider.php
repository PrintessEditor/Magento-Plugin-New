<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\CustomerFields;

use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Directory\Api\CountryInformationAcquirerInterface;
use Psr\Log\LoggerInterface;

/**
 * UDP-03: the logged-in customer's account data mapped to the form fields of one product ("Prefill user data").
 */
class UserDataProvider
{
    public const ATTR_ENABLED = 'printess_prefill_user_data';
    public const ATTR_MAP     = 'printess_user_data_map';

    public function __construct(
        private readonly ProductResource $productResource,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly CountryInformationAcquirerInterface $countryInformation,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return list<array{fieldName: string, source: string}> empty when the product does not prefill user data
     */
    public function getMap(int $productId, int $storeId): array
    {
        if ($productId <= 0) {
            return [];
        }
        // One attribute per call: with several codes Magento returns a scalar when only one has a value.
        // Store view value first, default scope as fallback (getAttributeRawValue does both).
        $enabled = $this->productResource->getAttributeRawValue($productId, self::ATTR_ENABLED, $storeId);
        if (!is_scalar($enabled) || !(int) $enabled) {
            return [];
        }
        $map = $this->productResource->getAttributeRawValue($productId, self::ATTR_MAP, $storeId);
        return UserDataSource::normalizeMap(is_string($map) ? $map : '');
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    public function getFormFields(int $customerId, int $productId, int $storeId): array
    {
        $map = $this->getMap($productId, $storeId);
        if ($map === []) {
            return [];
        }
        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (\Throwable $e) {
            $this->logger->warning('Printess prefill: customer could not be loaded', ['error' => $e->getMessage()]);
            return [];
        }

        $billing = null;
        $billingId = (string) $customer->getDefaultBilling();
        foreach ($customer->getAddresses() ?: [] as $address) {
            if ((string) $address->getId() === $billingId) {
                $billing = $this->addressData($address);
                break;
            }
        }

        $values = UserDataSource::values([
            'firstname' => $customer->getFirstname(),
            'lastname'  => $customer->getLastname(),
            'email'     => $customer->getEmail(),
        ], $billing);

        return UserDataSource::resolve($map, $values);
    }

    private function addressData(AddressInterface $address): array
    {
        $countryId = (string) $address->getCountryId();
        $countryName = '';
        if ($countryId !== '') {
            try {
                // Name in the current store's locale.
                $countryName = (string) $this->countryInformation->getCountryInfo($countryId)->getFullNameLocale();
            } catch (\Throwable) {
                $countryName = '';
            }
        }
        $region = $address->getRegion();
        return [
            'company'      => $address->getCompany(),
            'street'       => $address->getStreet() ?: [],
            'postcode'     => $address->getPostcode(),
            'city'         => $address->getCity(),
            'region'       => $region ? $region->getRegion() : '',
            'country_id'   => $countryId,
            'country_name' => $countryName,
            'telephone'    => $address->getTelephone(),
        ];
    }
}
