<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Plugin\Sales;

use Printess\PrintessEditor\Model\Options\InternalOptionFilter;

/**
 * Storefront order/invoice/shipment/credit memo item rows and order e-mails list an item's additional_options.
 * The Printess save token and thumbnail URL are internal and must not be shown to the customer there
 * (the admin order view uses its own renderer and keeps showing them).
 *
 * Declared on Magento\Sales\Block\Order\Item\Renderer\DefaultRenderer and
 * Magento\Sales\Block\Order\Email\Items\DefaultItems / Order\DefaultOrder (etc/di.xml).
 */
class HideInternalItemOptions
{
    /**
     * @param object $subject
     * @param mixed $result
     * @return mixed
     */
    public function afterGetItemOptions($subject, $result)
    {
        return is_array($result) ? InternalOptionFilter::strip($result) : $result;
    }
}
