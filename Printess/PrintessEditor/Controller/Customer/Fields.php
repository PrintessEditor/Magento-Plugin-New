<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Customer;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;
use Printess\PrintessEditor\Model\CustomerFields\Repository;
use Printess\PrintessEditor\Model\CustomerFields\UserDataProvider;
use Psr\Log\LoggerInterface;

/**
 * RMC-03 / UDP-03: Printess form field values of the logged-in customer for the current store view, e.g.
 * {"formFields":[{"name":"RM_CUST","value":"K-12345"},{"name":"firstName","value":"Sam"}]}. Guests get an empty list.
 *
 * GET product=<id> (optional): adds the account data mapped by that product ("Prefill user data"). These come after
 * RM_CUST, so a product mapping wins when both name the same field.
 *
 * Separate request because product pages come from the full-page cache and must not contain customer data.
 */
class Fields implements HttpGetActionInterface
{
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly CustomerSession $customerSession,
        private readonly StoreManagerInterface $storeManager,
        private readonly Repository $repository,
        private readonly RequestInterface $request,
        private readonly UserDataProvider $userDataProvider,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute()
    {
        $fields = [];
        if ($this->customerSession->isLoggedIn()) {
            $customerId = (int) $this->customerSession->getCustomerId();
            $storeId = (int) $this->storeManager->getStore()->getId();
            foreach ($this->repository->getValues($customerId, $storeId) as $name => $value) {
                if ($value !== '') {
                    $fields[] = ['name' => (string) $name, 'value' => (string) $value];
                }
            }
            $productId = (int) $this->request->getParam('product');
            if ($productId > 0) {
                try {
                    array_push($fields, ...$this->userDataProvider->getFormFields($customerId, $productId, $storeId));
                } catch (\Throwable $e) {
                    $this->logger->error('Printess prefill: user data could not be read', [
                        'product_id' => $productId,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }
        }
        $result = $this->jsonFactory->create();
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        $result->setHeader('Pragma', 'no-cache', true);
        return $result->setData(['formFields' => $fields]);
    }
}
