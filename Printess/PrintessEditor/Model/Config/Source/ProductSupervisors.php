<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Config\Source;

/** Same list as Supervisors, for the product attribute: empty means "use the store's Default Order Supervisor". */
class ProductSupervisors extends Supervisors
{
    protected function getEmptyLabel(): string
    {
        return (string) __('Use store default');
    }
}
