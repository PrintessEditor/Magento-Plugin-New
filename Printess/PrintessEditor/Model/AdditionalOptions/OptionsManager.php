<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\AdditionalOptions;

use Magento\Framework\Serialize\SerializerInterface;
use Psr\Log\LoggerInterface;

class OptionsManager
{
    private SerializerInterface $serializer;
    private LoggerInterface $logger;

    public function __construct(SerializerInterface $serializer, LoggerInterface $logger)
    {
        $this->serializer = $serializer;
        $this->logger = $logger;
    }

    public function transferAdditionalOptions($quote, $order): void
    {
        try {
            // getAllItems(), not getItems(): getItems() is only filled when the quote was loaded through the cart
            // repository (storefront checkout). Quotes built in code, like the order copy (OrderReproductionService),
            // leave it null, and the Printess design (save token) would silently not reach the order items.
            $quoteItemsById = [];
            foreach ($quote->getAllItems() as $quoteItem) {
                if (!$quoteItem->getParentItemId() && !$quoteItem->getParentItem() && $quoteItem->getId()) {
                    $quoteItemsById[$quoteItem->getId()] = $quoteItem;
                }
            }

            foreach ($order->getItems() as $orderItem) {
                if ($orderItem->getParentItemId()) {
                    continue;
                }
                $quoteItemId = $orderItem->getQuoteItemId();
                if (!isset($quoteItemsById[$quoteItemId])) {
                    continue;
                }
                $quoteItem = $quoteItemsById[$quoteItemId];
                if ($option = $quoteItem->getOptionByCode('additional_options')) {
                    $options = $orderItem->getProductOptions() ?: [];
                    $options['additional_options'] = $this->serializer->unserialize($option->getValue());
                    $orderItem->setProductOptions($options);
                }
            }
        } catch (\Exception $e) {
            $this->logger->critical($e);
        }
    }
}
