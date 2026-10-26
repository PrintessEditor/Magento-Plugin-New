<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Plugin\Helper;

use Magento\Catalog\Helper\Product\Configuration;
use Magento\Catalog\Model\Product\Configuration\Item\ItemInterface;
use Printess\PrintessEditor\Model\Options\InternalOptionFilter;

class ProductConfigurationPlugin
{
    /**
     * Strip Printess-internal keys (save token, thumbnail URL) from the
     * cart option list so they are never shown to the customer.
     */
    public function afterGetCustomOptions(Configuration $subject, array $result, ItemInterface $item): array
    {
        return InternalOptionFilter::strip($result);
    }
}
