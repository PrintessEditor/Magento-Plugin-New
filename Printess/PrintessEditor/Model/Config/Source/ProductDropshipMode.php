<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Config\Source;

use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;
use Printess\PrintessEditor\Model\Production\DropshipMode as Mode;

/**
 * Source for the `printess_dropship_mode` product attribute (D14 semantics):
 *  - ''           "No dropshipping": not set; a configurable child inherits from its parent,
 *                 an empty chain is never dropshipped. There is no store-level default.
 *  - none         "Never dropship": explicit opt-out that also overrides a dropshipping parent.
 *  - dropshipping  a dropship product definition (dropshipper + product) chosen on the product.
 *  - template      the dropshipping set up on the Printess template (productDefinitionId 0).
 */
class ProductDropshipMode extends AbstractSource
{
    public function getAllOptions(): array
    {
        if ($this->_options === null) {
            $this->_options = [
                ['value' => '', 'label' => __('No dropshipping')],
                ['value' => Mode::NONE, 'label' => __('Never dropship (overrides the parent product)')],
                ['value' => Mode::DROPSHIPPING, 'label' => __('Use a dropship product definition')],
                ['value' => Mode::TEMPLATE, 'label' => __('Use the dropshipping set up on the Printess template')],
            ];
        }

        return $this->_options;
    }
}
