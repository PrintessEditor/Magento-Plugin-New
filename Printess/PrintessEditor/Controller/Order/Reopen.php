<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Order;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\OrderItemRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Printess\PrintessEditor\Helper\Config as PrintessConfig;
use Printess\PrintessEditor\Model\PrintessApi;
use Printess\PrintessEditor\Model\Reorder\NewDesignPreset;
use Printess\PrintessEditor\Model\SaveTokenStatus;
use Psr\Log\LoggerInterface;

/**
 * Checks whether a past order item's Printess save token is still valid, and if so, returns
 * the same editor configuration shape as Project\Open — so the "Edit & Reorder" button on the
 * order view page can reopen the customer's original design in the Panel editor before adding
 * a fresh copy to the cart.
 *
 * Save tokens expire; this deliberately does NOT fall back to silently reordering a blank
 * product when the token has expired. It reports that back, with an editor configuration for a NEW
 * design on the product's template preset with the old order's settings (f22, NewDesignPreset), so
 * the order page can offer "Start a new design with the same settings".
 *
 * POST /printess/order/reopen
 *   order_item_id  int  Sales order item ID
 */
class Reopen extends Action implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly CustomerSession $customerSession,
        private readonly FormKeyValidator $formKeyValidator,
        private readonly OrderItemRepositoryInterface $orderItemRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly PrintessConfig $printessConfig,
        private readonly ResolverInterface $localeResolver,
        private readonly FormKey $formKey,
        private readonly UrlInterface $urlBuilder,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        if (!$this->formKeyValidator->validate($this->getRequest())) {
            return $result->setData(['success' => false, 'message' => __('Invalid form key.')]);
        }

        if (!$this->customerSession->isLoggedIn()) {
            return $result->setHttpResponseCode(401)->setData([
                'success' => false,
                'message' => __('You must be signed in to reorder a personalised item.'),
            ]);
        }

        $orderItemId = (int) $this->getRequest()->getParam('order_item_id');
        if ($orderItemId <= 0) {
            return $result->setData(['success' => false, 'message' => __('Missing order item.')]);
        }

        $customerId = (int) $this->customerSession->getCustomerId();

        try {
            $item = $this->orderItemRepository->get($orderItemId);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'message' => __('This order item no longer exists.')]);
        }

        try {
            $order = $this->orderRepository->get((int) $item->getOrderId());
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'message' => __('This order no longer exists.')]);
        }

        if ($customerId <= 0 || (int) $order->getCustomerId() !== $customerId) {
            return $result->setHttpResponseCode(403)->setData([
                'success' => false,
                'message' => __('You are not authorized to reorder this item.'),
            ]);
        }

        $additionalOptions = [];
        try {
            $additionalOptions = $item->getProductOptionByCode('additional_options') ?: [];
        } catch (\Throwable $e) {
            // Order item model without this accessor — treat as "not a Printess item" below.
        }
        $additionalOptions = is_array($additionalOptions) ? $additionalOptions : [];

        $saveToken = (string) ($additionalOptions['printess_save_token']['value'] ?? '');
        if ($saveToken === '') {
            return $result->setData([
                'success' => false,
                'message' => __('This item was not personalised with Printess and cannot be reopened here.'),
            ]);
        }

        $product = null;
        try {
            $product = $this->productRepository->getById((int) $item->getProductId());
        } catch (\Throwable $e) {
            // Product deleted/disabled — fall through, active will be reported as false below.
        }

        // Check with the credentials of the store the order was placed in (multi-store setups). Three-way: only a
        // definite "gone" offers a new design; an outage must not tell the customer their design expired.
        $orderStoreId = (int) $order->getStoreId() ?: null;
        $state = SaveTokenStatus::UNKNOWN;
        $serviceToken = $this->printessConfig->getServiceToken($orderStoreId);
        if ($serviceToken !== '') {
            try {
                $state = (new PrintessApi($serviceToken, $this->printessConfig->getApiUrl($orderStoreId)))
                    ->getSaveTokenState($saveToken);
            } catch (\Throwable $e) {
                $this->logger->warning('Printess: save token status check failed', ['error' => $e->getMessage()]);
            }
        }

        if ($state === SaveTokenStatus::UNKNOWN) {
            return $result->setData([
                'success' => false,
                'message' => __('Your design could not be checked right now. Please try again in a moment.'),
            ]);
        }

        if (!$product) {
            return $result->setData([
                'success'    => true,
                'active'     => false,
                'productUrl' => '',
                'message'    => __('This product is no longer available, so the design cannot be reordered.'),
            ]);
        }

        if ($state === SaveTokenStatus::ACTIVE) {
            return $result->setData([
                'success' => true,
                'active'  => true,
                'config'  => $this->editorConfig($product, $saveToken, $customerId, $item, false),
            ]);
        }

        // f22: the design expired. Offer a new design on the product's template, preset with the old order's settings.
        $template = trim((string) $product->getData('printess_template'));
        return $result->setData([
            'success'    => true,
            'active'     => false,
            'productUrl' => (string) $product->getProductUrl(),
            'message'    => $template !== ''
                ? __('This design is no longer available. You can start a new design with the same settings as before.')
                : __('This design is no longer available and needs to be recreated.'),
            'newDesign'  => $template !== '' ? $this->editorConfig($product, $template, $customerId, $item, true) : null,
        ]);
    }

    /**
     * Editor configuration in the shape printess-integration.js openFromProduct() takes. Reopening keeps the
     * customer's design (template = save token); a new design opens the product template with the old order's
     * settings as predefined form fields. Both carry the old option/variant selections for the add-to-cart form.
     *
     * @return array<string, mixed>
     */
    private function editorConfig(
        ProductInterface $product,
        string $templateName,
        int $customerId,
        OrderItemInterface $item,
        bool $newDesign
    ): array {
        $variantOptions = $this->buildVariantOptions($product);
        $customOptions  = $this->buildCustomOptions($product);
        $buyRequest     = $this->buyRequest($item);
        $productFields  = $product->getData('printess_form_fields');
        $productFields  = is_array($productFields) ? $productFields : $this->decodeJsonAttribute((string) $productFields);

        return [
            'shopToken'            => $this->printessConfig->getShopToken(),
            'panelLoaderUrl'       => $this->printessConfig->getPanelLoaderUrl(),
            'templateName'         => $templateName,
            'addToCartUrl'         => $this->urlBuilder->getUrl('checkout/cart/add', ['product' => (int) $product->getId()]),
            'productId'            => (string) $product->getId(),
            'productName'          => (string) $product->getName(),
            'productUrl'           => (string) $product->getProductUrl(),
            'formKey'              => $this->formKey->getFormKey(),
            'shopUserId'           => (string) $customerId,
            'variantOptions'       => $variantOptions,
            'customOptions'        => $customOptions,
            'predefinedFormFields' => $newDesign
                ? NewDesignPreset::formFields($productFields, $buyRequest, $customOptions, $variantOptions)
                : [],
            'presetSelections'     => NewDesignPreset::selections($buyRequest, $customOptions, $variantOptions),
            'pagePricing'          => is_array($product->getData('printess_page_pricing'))
                ? $product->getData('printess_page_pricing')
                : $this->decodeJsonAttribute((string) $product->getData('printess_page_pricing')),
            'basePrice'            => (float) $product->getFinalPrice(),
            'currencyCode'         => (string) $product->getStore()->getCurrentCurrencyCode(),
            'locale'               => str_replace('_', '-', $this->localeResolver->getLocale()),
            'theme'                => (string) ($product->getData('printess_theme') ?: $this->printessConfig->getEditorTheme()),
            'magicPhotobookTheme'  => (string) ($product->getData('printess_magic_photobook_theme') ?: ''),
            'printSettings'        => (string) ($product->getData('printess_print_settings') ?: $this->printessConfig->getPrintSettings()),
            'mergeTemplates'       => $this->buildMergeTemplates($product),
        ];
    }

    /** @return array<string, mixed> */
    private function buyRequest(OrderItemInterface $item): array
    {
        try {
            $value = $item->getProductOptionByCode('info_buyRequest');
        } catch (\Throwable) {
            return [];
        }
        return is_array($value) ? $value : [];
    }

    private function buildVariantOptions(ProductInterface $product): array
    {
        $variantOptions = [];

        if ($product->getTypeId() !== 'configurable') {
            return $variantOptions;
        }

        try {
            $typeInstance = $product->getTypeInstance();
            foreach ($typeInstance->getConfigurableAttributes($product) as $cfgAttr) {
                $productAttr = $cfgAttr->getProductAttribute();
                if (!$productAttr) {
                    continue;
                }

                $optionMap = [];
                foreach ((array) $cfgAttr->getOptions() as $opt) {
                    $label = (string) ($opt['label'] ?? '');
                    $vid = (string) ($opt['value_index'] ?? '');
                    if ($label !== '' && $vid !== '') {
                        $optionMap[$label] = $vid;
                    }
                }

                if (!empty($optionMap)) {
                    $variantOptions[] = [
                        'label' => (string) $productAttr->getFrontendLabel(),
                        'attributeId' => (string) $cfgAttr->getAttributeId(),
                        'options' => $optionMap,
                    ];
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $variantOptions;
    }

    private function buildCustomOptions(ProductInterface $product): array
    {
        $customOptions = [];

        try {
            foreach (($product->getOptions() ?: []) as $_opt) {
                if (!in_array($_opt->getType(), ['drop_down', 'radio'], true)) {
                    continue;
                }

                $values = [];
                foreach ((array) $_opt->getValues() as $_val) {
                    $values[] = [
                        'label' => (string) $_val->getTitle(),
                        'id'    => (string) $_val->getOptionTypeId(),
                        'price' => (float)  $_val->getPrice(),
                    ];
                }

                if (!empty($values)) {
                    $customOptions[] = [
                        'title' => (string) $_opt->getTitle(),
                        'optionId' => (string) $_opt->getOptionId(),
                        'values' => $values,
                    ];
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $customOptions;
    }

    private function buildMergeTemplates(ProductInterface $product): array
    {
        return array_values(array_map(
            static function (array $r): array {
                $entry = ['templateName' => (string) ($r['mergeTemplate'] ?? '')];
                if (($r['mergeMode'] ?? '') !== '') {
                    $entry['mergeMode'] = (string) $r['mergeMode'];
                }
                return $entry;
            },
            array_filter(
                (array) ($product->getData('printess_merge_template') ?: []),
                static fn($r): bool => is_array($r) && trim((string) ($r['mergeTemplate'] ?? '')) !== ''
            )
        ));
    }

    private function decodeJsonAttribute(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
