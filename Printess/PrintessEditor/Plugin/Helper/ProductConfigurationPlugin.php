<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Plugin\Helper;

use Magento\Catalog\Helper\Product\Configuration;
use Magento\Catalog\Model\Product\Configuration\Item\ItemInterface;
use Printess\PrintessEditor\Model\Options\InternalOptionFilter;
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
     * Strip Printess-internal entries (save token, thumbnail URL, ...) from the cart option list so they are never
     * shown to the customer, and render friendlier labels/values for the remaining Printess custom options.
     * InternalOptionFilter matches by `printess_` key and, should the keys have been lost, by label.
     */
    public function afterGetCustomOptions(Configuration $subject, array $result, ItemInterface $item): array
    {
        return $this->friendlyOptionLabelFormatter->format(InternalOptionFilter::strip($result), $item->getStoreId());
    }
}
