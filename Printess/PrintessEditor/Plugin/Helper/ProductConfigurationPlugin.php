<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Plugin\Helper;

use Magento\Catalog\Helper\Product\Configuration;
use Magento\Catalog\Model\Product\Configuration\Item\ItemInterface;

class ProductConfigurationPlugin
{
    /**
     * Strip Printess-internal keys (save token, thumbnail URL) from the
     * cart option list so they are never shown to the customer.
     */
    public function afterGetCustomOptions(Configuration $subject, array $result, ItemInterface $item): array
    {
        return array_filter($result, function ($key) {
            return strpos((string)$key, 'printess_') !== 0;
        }, ARRAY_FILTER_USE_KEY);
    }
}
