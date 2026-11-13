<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Adminhtml\Supervisor;

class MassRemove extends AbstractRoleMassAction
{
    protected function getRoleValue(): int
    {
        return 0;
    }
}
