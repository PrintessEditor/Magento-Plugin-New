<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\ResourceModel\ApprovalSubmission;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Printess\PrintessEditor\Model\Approval\Submission;
use Printess\PrintessEditor\Model\ResourceModel\ApprovalSubmission as SubmissionResource;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'submission_id';

    protected function _construct(): void
    {
        $this->_init(Submission::class, SubmissionResource::class);
    }
}
