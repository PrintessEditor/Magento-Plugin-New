<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model;

use Printess\PrintessEditor\Model\ResourceModel\Project as ProjectResource;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\StoreManagerInterface;

class ProjectManager
{
    public function __construct(
        private readonly ProjectFactory $projectFactory,
        private readonly ProjectResource $projectResource,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly TimezoneInterface $timezone,
        private readonly ProjectConfig $projectConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly DateTime $dateTime
    ) {
    }

    public function save(
        int $customerId,
        int $productId,
        string $saveToken,
        ?string $thumbnailUrl = null,
        ?int $projectId = null,
        ?string $name = null
    ): Project {
        $this->assertCustomer($customerId);
        $saveToken = trim($saveToken);
        if ($saveToken === '') {
            throw new InputException(__('A Printess save token is required.'));
        }

        if (mb_strlen($saveToken) > 512) {
            throw new InputException(__('The Printess save token must not exceed 512 characters.'));
        }

        $storeId = (int) $this->storeManager->getStore()->getId();
        $product = $this->productRepository->getById($productId, false, $storeId);
        if (trim((string) $product->getData('printess_template')) === '') {
            throw new InputException(__('This product does not support Printess projects.'));
        }

        $project = $projectId ? $this->getOwnedProject($projectId, $customerId) : $this->projectFactory->create();

        if ($project->getId() && (int) $project->getData('product_id') !== $productId) {
            throw new AuthorizationException(__('The project does not belong to this product.'));
        }

        if (!$project->getId()) {
            $project->setData([
                'customer_id' => $customerId,
                'product_id' => $productId,
                'sku' => (string) $product->getSku(),
                'product_name' => (string) $product->getName(),
                'name' => ($name !== null && trim($name) !== '') ? trim($name) : $this->getDefaultName((string) $product->getName()),
                'store_id' => $storeId
            ]);
        } elseif ($name !== null && trim($name) !== '') {
            $project->setData('name', trim($name));
        }

        $storeId = (int) $project->getData('store_id');
        $savedAt = $this->dateTime->gmtTimestamp();
        $project->setData('save_token', $saveToken);
        $project->setData('thumbnail_url', $this->validateThumbnailUrl($thumbnailUrl));
        $project->setData('updated_at', $this->dateTime->gmtDate(null, $savedAt));
        $project->setData('expires_at', $this->dateTime->gmtDate(
            null,
            $savedAt + ($this->projectConfig->getRetentionDays($storeId) * 86400)
        ));

        // Only reset reminder/ordered timestamps when the project has not yet been ordered.
        // Preserving these on already-ordered projects prevents spurious reminder emails and
        // expiry cron deletion after a customer edits a design post-checkout.
        if ($project->getData('ordered_at') === null) {
            $project->setData('order_reminder_sent_at', null);
            $project->setData('removal_reminder_sent_at', null);
        }
        $this->projectResource->save($project);

        return $project;
    }

    public function rename(int $projectId, int $customerId, string $name): Project
    {
        $this->assertCustomer($customerId);
        $name = trim($name);
        if ($name === '') {
            throw new InputException(__('Enter a project name.'));
        }

        if (mb_strlen($name) > 255) {
            throw new InputException(__('The project name must not exceed 255 characters.'));
        }

        $project = $this->getOwnedProject($projectId, $customerId);
        $project->setData('name', $name);
        $this->projectResource->save($project);

        return $project;
    }

    public function delete(int $projectId, int $customerId): void
    {
        $this->assertCustomer($customerId);
        $this->projectResource->delete($this->getOwnedProject($projectId, $customerId));
    }

    public function getOwnedProject(int $projectId, int $customerId): Project
    {
        $this->assertCustomer($customerId);
        $project = $this->projectFactory->create();
        $this->projectResource->load($project, $projectId);

        if (!$project->getId()) {
            throw new NoSuchEntityException(__('The project no longer exists.'));
        }

        if ((int) $project->getData('customer_id') !== $customerId) {
            throw new AuthorizationException(__('You are not authorized to access this project.'));
        }

        return $project;
    }

    private function getDefaultName(string $productName): string
    {
        return mb_substr(
            sprintf('%s - %s', $productName, $this->timezone->date()->format('d M Y H:i')),
            0,
            255
        );
    }

    private function assertCustomer(int $customerId): void
    {
        if ($customerId <= 0) {
            throw new AuthorizationException(__('You must be signed in to manage Printess projects.'));
        }
    }

    private function validateThumbnailUrl(?string $thumbnailUrl): ?string
    {
        $thumbnailUrl = trim((string) $thumbnailUrl);

        // The Printess SDK may pass action signals (e.g. "close") as the thumbnail
        // argument rather than a real URL. Treat anything that isn't an HTTPS URL
        // as absent — never fail the save because of an invalid thumbnail.
        if ($thumbnailUrl === '' || !str_starts_with(strtolower($thumbnailUrl), 'https://')) {
            return null;
        }

        if (mb_strlen($thumbnailUrl) > 2048 || filter_var($thumbnailUrl, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $thumbnailUrl;
    }
}
