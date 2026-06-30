<?php
namespace Printess\PrintessEditor\Block\Cart;

use Printess\PrintessEditor\Helper\Config;
use Magento\Checkout\Block\Cart\Item\Renderer\Actions\Generic;
use Magento\Checkout\Helper\Cart;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\View\Element\Template;

class EditButton extends Generic
{
    private Config $printessConfig;
    private Cart $cartHelper;
    private SerializerInterface $serializer;

    public function __construct(
        Template\Context $context,
        Config $printessConfig,
        Cart $cartHelper,
        SerializerInterface $serializer,
        array $data = []
    ) {
        $this->printessConfig = $printessConfig;
        $this->cartHelper     = $cartHelper;
        $this->serializer     = $serializer;
        parent::__construct($context, $data);
    }

    private function getPrintessOptions(): array
    {
        $item = $this->getItem();
        if ($opt = $item->getOptionByCode('additional_options')) {
            $decoded = $this->serializer->unserialize($opt->getValue());
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    public function getSaveToken(): string
    {
        return (string)($this->getPrintessOptions()['printess_save_token']['value'] ?? '');
    }

    public function getThumbnailUrl(): string
    {
        return (string)($this->getPrintessOptions()['printess_thumbnail_url']['value'] ?? '');
    }

    public function isPrintessItem(): bool
    {
        return $this->getSaveToken() !== '';
    }

    public function getItemData(): array
    {
        $item    = $this->getItem();
        $product = $item->getProduct();

        return [
            'itemId'       => (int)$item->getId(),
            'sku'          => $product->getSku(),
            'productId'    => (int)$product->getId(),
            'qty'          => (int)$item->getQty(),
            'saveToken'    => $this->getSaveToken(),
            'shopToken'    => $this->printessConfig->getShopToken(),
            'addToCartUrl' => $this->cartHelper->getAddUrl($product),
            'deleteUrl'    => $this->cartHelper->getDeletePostJson($item),
        ];
    }
}
