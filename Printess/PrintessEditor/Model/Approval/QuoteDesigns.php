<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Approval;

use Magento\Framework\Serialize\SerializerInterface;

/** Reads the Printess save token stored in a quote/order item's additional_options. */
class QuoteDesigns
{
    public function __construct(private readonly SerializerInterface $serializer)
    {
    }

    /** @param \Magento\Quote\Model\Quote\Item|\Magento\Sales\Model\Order\Item $item */
    public function getSaveToken($item): string
    {
        $raw = null;
        if ($item instanceof \Magento\Sales\Model\Order\Item) {
            $options = $item->getProductOptions() ?: [];
            $additional = $options['additional_options'] ?? [];
            return (string) ($additional['printess_save_token']['value'] ?? '');
        }
        $option = $item->getOptionByCode('additional_options');
        $raw = $option ? $option->getValue() : null;
        if (!$raw) {
            return '';
        }
        try {
            $data = $this->serializer->unserialize($raw);
        } catch (\Throwable) {
            return '';
        }
        return is_array($data) ? (string) ($data['printess_save_token']['value'] ?? '') : '';
    }
}
