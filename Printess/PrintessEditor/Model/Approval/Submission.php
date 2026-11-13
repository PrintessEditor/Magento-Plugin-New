<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Approval;

use Magento\Framework\Model\AbstractModel;
use Printess\PrintessEditor\Model\ResourceModel\ApprovalSubmission as SubmissionResource;

/**
 * One supervisor-approval workflow per saved project (printess_approval_submission).
 */
class Submission extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(SubmissionResource::class);
    }

    public function getStatus(): string
    {
        return (string) $this->getData('status');
    }

    public function getProjectId(): int
    {
        return (int) $this->getData('project_id');
    }

    public function getCustomerId(): int
    {
        return (int) $this->getData('customer_id');
    }

    public function getWebsiteId(): int
    {
        return (int) $this->getData('website_id');
    }

    public function getStoreId(): int
    {
        return (int) $this->getData('store_id');
    }

    public function getVersion(): int
    {
        return (int) $this->getData('version');
    }

    /** @return array<string,mixed> */
    public function getBuyRequest(): array
    {
        $raw = (string) $this->getData('buy_request_json');
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : [];
    }
}
