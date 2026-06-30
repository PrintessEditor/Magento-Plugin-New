<?php
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
            $quoteItemsById = [];
            foreach ($quote->getItems() as $quoteItem) {
                if (!$quoteItem->getParentItemId()) {
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
