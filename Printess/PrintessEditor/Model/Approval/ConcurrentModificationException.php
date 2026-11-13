<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Approval;

use Magento\Framework\Exception\LocalizedException;

/** Another request changed the submission since it was loaded (optimistic lock, column `version`). */
class ConcurrentModificationException extends LocalizedException
{
}
