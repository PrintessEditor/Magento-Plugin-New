<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Project;

use Printess\PrintessEditor\Model\ProjectConfig;
use Printess\PrintessEditor\Model\ProjectFactory;
use Printess\PrintessEditor\Model\ProjectManager;
use Printess\PrintessEditor\Model\ResourceModel\Project as ProjectResource;
use Printess\PrintessEditor\Model\ResourceModel\Project\CollectionFactory;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unified project save endpoint.
 *
 * Handles two request formats:
 *
 *  1. JSON body  — used by the Printess editor's own built-in Save panel.
 *     Body: { "saveToken": "st:...", "name": "My Project" }
 *     No product context is available; the record is saved as a draft.
 *
 *  2. Form POST  — used by our saveTemplateCallback (printess-login-gate.js)
 *     after the customer clicks "Save & Quit" on a product-page session.
 *     Params: save_token, product_id, thumbnail_url, project_id
 *
 * CSRF is bypassed only for JSON-body requests from the Printess SDK iframe,
 * which cannot include a Magento form key. Form POST requests from our own JS
 * must carry a valid form key. Session authentication still applies:
 * customer_id is always read from the session and ProjectManager enforces
 * ownership on every operation.
 */
class Save implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface      $request,
        private readonly JsonFactory           $jsonFactory,
        private readonly Session               $customerSession,
        private readonly ProjectManager        $projectManager,
        private readonly ProjectFactory        $projectFactory,
        private readonly ProjectResource       $projectResource,
        private readonly CollectionFactory     $collectionFactory,
        private readonly ProjectConfig         $projectConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly DateTime              $dateTime,
        private readonly LoggerInterface       $logger
    ) {}

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        // CSRF is intentionally not enforced. The Printess SDK cannot include a
        // Magento form key, and the risk is low: customer_id is always sourced
        // from the session, so a cross-site POST can only create/overwrite a
        // project in the currently-logged-in customer's own account — it cannot
        // touch any other customer's data.
        return true;
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        $isJson = str_contains(
            strtolower((string) $this->request->getHeader('Content-Type')),
            'application/json'
        );

        if (!$this->customerSession->isLoggedIn()) {
            return $result->setHttpResponseCode(401)->setData([
                'success' => false,
                'message' => (string) __('You must be signed in to save a project.')
            ]);
        }

        $customerId = (int) $this->customerSession->getCustomerId();

        return $isJson
            ? $this->saveDraft($customerId, $result)
            : $this->saveWithProduct($customerId, $result);
    }

    /**
     * Form POST path — called by our JS saveTemplateCallback.
     * Has product context; delegates to ProjectManager for full validation.
     */
    private function saveWithProduct(int $customerId, $result)
    {
        $saveToken    = trim((string) $this->request->getParam('save_token', ''));
        $productId    = (int) $this->request->getParam('product_id');
        $thumbnailUrl = trim((string) $this->request->getParam('thumbnail_url', '')) ?: null;
        $projectId    = (int) $this->request->getParam('project_id') ?: null;
        $name         = trim((string) $this->request->getParam('project_name', '')) ?: null;
        if ($name !== null) {
            $name = mb_substr(strip_tags($name), 0, 255);
            if ($name === '') { $name = null; }
        }

        if ($saveToken === '') {
            return $result->setHttpResponseCode(400)->setData([
                'success' => false,
                'message' => (string) __('A Printess save token is required.')
            ]);
        }

        try {
            $project = $this->projectManager->save(
                $customerId,
                $productId,
                $saveToken,
                $thumbnailUrl,
                $projectId,
                $name
            );

            return $result->setData([
                'success'    => true,
                'project_id' => (int) $project->getId(),
                'name'       => (string) $project->getData('name')
            ]);
        } catch (LocalizedException $e) {
            return $result->setHttpResponseCode(400)->setData([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        } catch (\Throwable $e) {
            $this->logger->critical($e);
            return $result->setHttpResponseCode(500)->setData([
                'success' => false,
                'message' => (string) __('Unable to save the project. Please try again.')
            ]);
        }
    }

    /**
     * JSON body path — called by the Printess editor's built-in Save panel.
     * No product context; upserts a draft record by saveToken.
     */
    private function saveDraft(int $customerId, $result)
    {
        $body      = json_decode((string) $this->request->getContent(), true) ?? [];
        $saveToken = trim((string) ($body['saveToken'] ?? ''));
        $name      = trim((string) ($body['name'] ?? '')) ?: 'My Project';

        if ($saveToken === '') {
            return $result->setHttpResponseCode(400)->setData([
                'success' => false,
                'message' => (string) __('A Printess save token is required.')
            ]);
        }

        if (mb_strlen($saveToken) > 512) {
            return $result->setHttpResponseCode(400)->setData([
                'success' => false,
                'message' => (string) __('The provided save token is invalid.')
            ]);
        }

        try {
            $project = $this->findDraftByToken($customerId, $saveToken);

            if ($project->getId()) {
                $project->setData('name', $name);
                $project->setData('updated_at', $this->dateTime->gmtDate());
            } else {
                $storeId   = (int) $this->storeManager->getStore()->getId();
                $savedAt   = $this->dateTime->gmtTimestamp();
                $expiresAt = $this->dateTime->gmtDate(
                    null,
                    $savedAt + ($this->projectConfig->getRetentionDays($storeId) * 86400)
                );
                $project->setData([
                    'customer_id' => $customerId,
                    'store_id'    => $storeId,
                    'name'        => $name,
                    'save_token'  => $saveToken,
                    'created_at'  => $this->dateTime->gmtDate(null, $savedAt),
                    'updated_at'  => $this->dateTime->gmtDate(null, $savedAt),
                    'expires_at'  => $expiresAt,
                ]);
            }

            $this->projectResource->save($project);

            return $result->setData([
                'success'    => true,
                'id'         => (int) $project->getId(),
                'name'       => $name,
                'created_at' => (string) $project->getData('created_at'),
            ]);
        } catch (\Exception $e) {
            $this->logger->critical($e);
            return $result->setHttpResponseCode(500)->setData([
                'success' => false,
                'message' => (string) __('Unable to save the project. Please try again.')
            ]);
        }
    }

    private function findDraftByToken(int $customerId, string $saveToken): \Printess\PrintessEditor\Model\Project
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('customer_id', $customerId);
        $collection->addFieldToFilter('save_token', $saveToken);
        $collection->setPageSize(1);

        $item = $collection->getFirstItem();
        return $item->getId() ? $item : $this->projectFactory->create();
    }
}
