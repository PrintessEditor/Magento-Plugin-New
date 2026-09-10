<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Plugin\Cart;

use Magento\Catalog\Block\Product\Image;
use Magento\Checkout\Block\Cart\Item\Renderer;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Quote\Model\Quote\Item\AbstractItem;

class QuoteItemThumbnailUpdate
{
    public function __construct(private SerializerInterface $serializer)
    {
    }

    public function afterGetImage(Renderer $subject, Image $result): Image
    {
        $thumbnailUrl = $this->resolveThumbnailUrl($subject->getItem());
        if ($thumbnailUrl !== null) {
            $result->setImageUrl($thumbnailUrl);
            $existing = $result->getCustomAttributes();
            $result->setCustomAttributes(array_merge($existing ?: [], ['data-printess-thumbnail' => '1']));
        }
        return $result;
    }

    private function resolveThumbnailUrl($quoteItem): ?string
    {
        if (!$quoteItem instanceof AbstractItem) {
            return null;
        }

        $option = $quoteItem->getOptionByCode('additional_options');
        if (!$option) {
            return null;
        }

        try {
            $options = $this->serializer->unserialize((string)$option->getValue());
        } catch (\Throwable) {
            return null;
        }

        $url = (string)($options['printess_thumbnail_url']['value'] ?? '');
        return $url !== '' ? $url : null;
    }
}
