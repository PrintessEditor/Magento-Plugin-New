<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Plugin\Helper;

use Magento\Catalog\Helper\Product\Configuration;
use Magento\Catalog\Model\Product\Configuration\Item\ItemInterface;
use Printess\PrintessEditor\Model\Sales\FriendlyOptionLabelFormatter;

class ProductConfigurationPlugin
{
    /**
     * @var FriendlyOptionLabelFormatter
     */
    private $friendlyOptionLabelFormatter;

    public function __construct(FriendlyOptionLabelFormatter $friendlyOptionLabelFormatter)
    {
        $this->friendlyOptionLabelFormatter = $friendlyOptionLabelFormatter;
    }

    /**
     * Strip Printess-internal keys (save token, thumbnail URL) from the
     * cart option list so they are never shown to the customer, and render
     * friendlier labels/values for the remaining Printess custom options.
     */
    public function afterGetCustomOptions(Configuration $subject, array $result, ItemInterface $item): array
    {
        $filtered = array_filter($result, function ($key) {
            return strpos((string)$key, 'printess_') !== 0;
        }, ARRAY_FILTER_USE_KEY);

        return $this->friendlyOptionLabelFormatter->format($filtered, $item->getStoreId());
    }
}
