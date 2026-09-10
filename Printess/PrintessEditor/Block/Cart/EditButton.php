<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Block\Cart;

use Printess\PrintessEditor\Helper\Config;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Checkout\Block\Cart\Item\Renderer\Actions\Generic;
use Magento\Checkout\Helper\Cart;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\View\Element\Template;

class EditButton extends Generic
{
    /**
     * @var Config
     */
    private Config $printessConfig;

    /**
     * @var Cart
     */
    private Cart $cartHelper;

    /**
     * @var SerializerInterface
     */
    private SerializerInterface $serializer;

    /**
     * @var ResolverInterface
     */
    private ResolverInterface $localeResolver;

    /**
     * @var ProductRepositoryInterface
     */
    private ProductRepositoryInterface $productRepository;

    /**
     * Constructor.
     *
     * @param Template\Context               $context
     * @param Config                         $printessConfig
     * @param Cart                           $cartHelper
     * @param SerializerInterface            $serializer
     * @param ResolverInterface              $localeResolver
     * @param ProductRepositoryInterface     $productRepository
     * @param array                          $data
     */
    public function __construct(
        Template\Context $context,
        Config $printessConfig,
        Cart $cartHelper,
        SerializerInterface $serializer,
        ResolverInterface $localeResolver,
        ProductRepositoryInterface $productRepository,
        array $data = []
    ) {
        $this->printessConfig    = $printessConfig;
        $this->cartHelper        = $cartHelper;
        $this->serializer        = $serializer;
        $this->localeResolver    = $localeResolver;
        $this->productRepository = $productRepository;
        parent::__construct($context, $data);
    }

    /**
     * Retrieve decoded Printess additional_options for current cart item.
     *
     * @return array
     */
    private function getPrintessOptions(): array
    {
        $item = $this->getItem();
        if ($opt = $item->getOptionByCode('additional_options')) {
            $decoded = $this->serializer->unserialize($opt->getValue());
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /**
     * Return the Printess save token stored on the cart item.
     *
     * @return string
     */
    public function getSaveToken(): string
    {
        return (string)($this->getPrintessOptions()['printess_save_token']['value'] ?? '');
    }

    /**
     * Return the Printess thumbnail URL stored on the cart item.
     *
     * @return string
     */
    public function getThumbnailUrl(): string
    {
        return (string)($this->getPrintessOptions()['printess_thumbnail_url']['value'] ?? '');
    }

    /**
     * Return true when the item has a Printess save token.
     *
     * @return bool
     */
    public function isPrintessItem(): bool
    {
        return $this->getSaveToken() !== '';
    }

    /**
     * Return included page count stored on the cart item, or -1 if not set.
     *
     * @return int
     */
    public function getIncludedPages(): int
    {
        $val = $this->getPrintessOptions()['printess_included_pages']['value'] ?? null;
        return $val !== null ? (int)$val : -1;
    }

    /**
     * Return the Printess project ID stored on the cart item.
     *
     * @return int
     */
    public function getProjectId(): int
    {
        $val = $this->getPrintessOptions()['printess_project_id']['value'] ?? null;
        return $val !== null ? (int)$val : 0;
    }

    /**
     * Return stored form fields as {name, value} pairs for Printess loadCfg.
     *
     * @return array
     */
    public function getStoredFormFields(): array
    {
        $raw = $this->getPrintessOptions()['printess_form_fields']['value'] ?? '';
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        // Normalise to [{name, value}] array (the format Printess loadCfg.formFields expects).
        $fields = [];
        foreach ($decoded as $name => $value) {
            if (is_string($name) && $name !== '') {
                $fields[] = ['name' => $name, 'value' => (string)$value];
            }
        }
        return $fields;
    }

    /**
     * Build custom option sync config for a product.
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     *
     * @return array
     */
    private function buildCustomOptions(\Magento\Catalog\Api\Data\ProductInterface $product): array
    {
        $customOptions = [];
        try {
            foreach (($product->getOptions() ?: []) as $opt) {
                if (!in_array($opt->getType(), ['drop_down', 'radio'], true)) {
                    continue;
                }
                $values = [];
                foreach ((array)$opt->getValues() as $val) {
                    $values[] = [
                        'label' => (string)$val->getTitle(),
                        'id'    => (string)$val->getOptionTypeId(),
                        'price' => (float)$val->getPrice(),
                    ];
                }
                if (!empty($values)) {
                    $customOptions[] = [
                        'title'    => (string)$opt->getTitle(),
                        'optionId' => (string)$opt->getOptionId(),
                        'values'   => $values,
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Non-critical: skip options if they cannot be read
            unset($e);
            return [];
        }
        return $customOptions;
    }

    /**
     * Return JS config array for the cart item editor.
     *
     * @return array
     */
    public function getItemData(): array
    {
        $item  = $this->getItem();
        $store = $this->_storeManager->getStore();

        try {
            $product = $this->productRepository->getById(
                (int)$item->getProductId(),
                false,
                (int)$store->getId()
            );
        } catch (\Throwable $e) {
            $product = $item->getProduct();
        }

        // Use getFinalPrice() on the freshly-loaded product — identical to what Project/Open.php
        // does for the customer account page, where size/cover price changes work correctly.
        // This is the base price BEFORE custom option adjustments; JS adds them on top via
        // _selectedOptionPrices, matching the initialisation in getLivePriceInfoFromApi.
        $basePrice     = (float)$product->getFinalPrice();
        $customOptions = $this->buildCustomOptions($product);

        $pagePricingRaw = $product->getData('printess_page_pricing');
        $pagePricing    = is_array($pagePricingRaw)
            ? $pagePricingRaw
            : (json_decode((string)$pagePricingRaw, true) ?: []);

        $productTheme  = (string)($product->getData('printess_theme') ?? '');
        $theme         = $productTheme !== '' ? $productTheme : $this->printessConfig->getEditorTheme();

        $productPrint  = (string)($product->getData('printess_print_settings') ?? '');
        $printSettings = $productPrint !== '' ? $productPrint : $this->printessConfig->getPrintSettings();

        return [
            'itemId'             => (int)$item->getId(),
            'sku'                => $product->getSku(),
            'productId'          => (int)$product->getId(),
            'qty'                => (int)$item->getQty(),
            'saveToken'          => $this->getSaveToken(),
            'shopToken'          => $this->printessConfig->getShopToken(),
            'basePrice'          => $basePrice,
            'customOptions'      => $customOptions,
            'pagePricing'        => $pagePricing,
            'currencyCode'       => $store->getCurrentCurrencyCode(),
            'locale'             => str_replace('_', '-', $this->localeResolver->getLocale()),
            'minPages'           => $this->getIncludedPages(),
            'formFields'         => $this->getStoredFormFields(),
            'theme'              => $theme,
            'magicPhotobookTheme'=> (string)($product->getData('printess_magic_photobook_theme') ?? ''),
            'printSettings'      => $printSettings,
            'addToCartUrl'       => $this->cartHelper->getAddUrl($product),
            'deleteUrl'          => $this->cartHelper->getDeletePostJson($item),
            'saveUrl'            => $this->getUrl('printess/project/save'),
            'projectId'          => $this->getProjectId(),
        ];
    }
}
