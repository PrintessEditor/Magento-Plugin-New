<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Approval;

use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Store\Model\StoreManagerInterface;
use Printess\PrintessEditor\Setup\Patch\Data\AddOrderSupervisorAttribute;
use Psr\Log\LoggerInterface;

/**
 * Resolves the Default Order Supervisor of a design: product attribute (store view value) → store setting →
 * null (= notify all supervisors of the website).
 */
class SupervisorResolver
{
    public function __construct(
        private readonly ProductResource $productResource,
        private readonly ApprovalConfig $approvalConfig,
        private readonly SupervisorProvider $supervisorProvider,
        private readonly StoreManagerInterface $storeManager,
        private readonly SupervisorResolution $resolution,
        private readonly LoggerInterface $logger
    ) {
    }

    public function resolve(?int $productId, int $storeId): ?int
    {
        $websiteId = (int) $this->storeManager->getStore($storeId)->getWebsiteId();
        $productValue = null;
        if ($productId) {
            $raw = $this->productResource->getAttributeRawValue(
                $productId,
                AddOrderSupervisorAttribute::ATTRIBUTE_CODE,
                $storeId
            );
            $productValue = is_array($raw) ? null : ((int) $raw ?: null);
        }
        $resolved = $this->resolution->resolve(
            $productValue,
            $this->approvalConfig->getDefaultSupervisorId($storeId),
            fn (int $id): bool => $this->supervisorProvider->isSupervisorForWebsite($id, $websiteId)
        );
        if ($resolved === null) {
            $this->logger->warning('Printess approval: no valid Default Order Supervisor, notifying all supervisors.', [
                'product_id' => $productId,
                'store_id'   => $storeId,
            ]);
        }
        return $resolved;
    }

    /**
     * Customer ids to notify for a new submission: the resolved supervisor, or all supervisors of the website.
     *
     * @return int[]
     */
    public function getRecipients(?int $assignedSupervisorId, int $websiteId, int $excludeCustomerId = 0): array
    {
        if ($assignedSupervisorId && $assignedSupervisorId !== $excludeCustomerId
            && $this->supervisorProvider->isSupervisorForWebsite($assignedSupervisorId, $websiteId)) {
            return [$assignedSupervisorId];
        }
        return array_values(array_filter(
            array_keys($this->supervisorProvider->getSupervisors($websiteId)),
            fn (int $id): bool => $id !== $excludeCustomerId
        ));
    }
}
