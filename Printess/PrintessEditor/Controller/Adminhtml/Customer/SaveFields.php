<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Adminhtml\Customer;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;
use Printess\PrintessEditor\Model\CustomerFields\FieldValue;
use Printess\PrintessEditor\Model\CustomerFields\Repository;
use Psr\Log\LoggerInterface;

/**
 * RMC-02: saves the Printess form field values (RM_CUST) of one customer per store view.
 * POST: form_key, customer_id, values = JSON {"RM_CUST": {"<store id>": "<value>", ...}}
 */
class SaveFields extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magento_Customer::manage';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly Repository $repository,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $customerId = (int) $this->getRequest()->getParam('customer_id');
        try {
            $this->customerRepository->getById($customerId);
        } catch (\Throwable) {
            return $result->setData(['success' => false, 'message' => (string) __('This customer no longer exists.')]);
        }
        $values = json_decode((string) $this->getRequest()->getParam('values', ''), true);
        if (!is_array($values)) {
            return $result->setData(['success' => false, 'message' => (string) __('Nothing to save.')]);
        }
        $storeIds = array_map('intval', array_keys($this->storeManager->getStores()));
        try {
            $saved = 0;
            foreach (FieldValue::FIELDS as $field) {
                if (!isset($values[$field]) || !is_array($values[$field])) {
                    continue;
                }
                $byStore = [];
                foreach ($values[$field] as $storeId => $value) {
                    if (!in_array((int) $storeId, $storeIds, true) || !is_scalar($value)) {
                        continue;
                    }
                    $byStore[(int) $storeId] = FieldValue::normalize((string) $value);
                }
                $this->repository->saveForCustomer($customerId, $field, $byStore);
                $saved += count($byStore);
            }
        } catch (\InvalidArgumentException $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error('Printess: saving customer form fields failed: ' . $e->getMessage());
            return $result->setData([
                'success' => false,
                'message' => (string) __('The values could not be saved. Please try again.'),
            ]);
        }
        return $result->setData([
            'success' => true,
            'message' => (string) __('Saved the Printess fields for %1 store view(s).', $saved),
        ]);
    }
}
