<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Printess\PrintessEditor\Helper\Config;

/**
 * Store-config select for "Reproducing dropship orders" (PMD-21): send again under the order number with a suffix,
 * or ask each time whether to duplicate the order instead (OrderReproductionService).
 */
class DropshipReproductionMode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            [
                'value' => Config::REPRODUCTION_MODE_SUFFIX,
                'label' => __('Send again under the order number with a suffix (-R1, -R2, ...)'),
            ],
            [
                'value' => Config::REPRODUCTION_MODE_ASK,
                'label' => __('Ask each time: send again, or duplicate the order and dropship the copy'),
            ],
        ];
    }
}
