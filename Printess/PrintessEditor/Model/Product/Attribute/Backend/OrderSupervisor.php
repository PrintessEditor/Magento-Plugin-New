<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Product\Attribute\Backend;

use Magento\Eav\Model\Entity\Attribute\Backend\AbstractBackend;
use Magento\Framework\Exception\LocalizedException;
use Printess\PrintessEditor\Model\Approval\SupervisorProvider;

/** Product-level Default Order Supervisor: empty or a customer with the Supervisor role. */
class OrderSupervisor extends AbstractBackend
{
    public function __construct(private readonly SupervisorProvider $supervisorProvider)
    {
    }

    public function validate($object)
    {
        $code = $this->getAttribute()->getAttributeCode();
        $value = (int) $object->getData($code);
        if ($value > 0 && !$this->supervisorProvider->isSupervisor($value)) {
            throw new LocalizedException(__(
                'The Default Order Supervisor must be a customer with the Printess Supervisor role.'
            ));
        }
        if ($value <= 0) {
            $object->setData($code, null);
        }
        return parent::validate($object);
    }
}
