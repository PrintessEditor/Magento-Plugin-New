<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Approval;

/** Thrown by Workflow for an action that is not allowed in the current status (PURE, no Magento base class). */
class InvalidTransitionException extends \DomainException
{
}
