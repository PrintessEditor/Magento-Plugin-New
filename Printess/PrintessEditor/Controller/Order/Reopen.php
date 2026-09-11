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
use Magento\Sales\Api\OrderItemRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Printess\PrintessEditor\Helper\Config as PrintessConfig;
use Printess\PrintessEditor\Model\PrintessApi;
use Psr\Log\LoggerInterface;

/**
 * Checks whether a past order item's Printess save token is still valid, and if so, returns
 * the same editor configuration shape as Project\Open — so the "Edit & Reorder" button on the
 * order view page can reopen the customer's original design in the Panel editor before adding
 * a fresh copy to the cart.
 *
 * Save tokens expire; this deliberately does NOT fall back to silently reordering a blank
 * product when the token has expired — it reports that back so the front end can point the
 * customer at a fresh personalisation instead.
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

        $active = false;
        $serviceToken = $this->printessConfig->getServiceToken();
        if ($serviceToken !== '') {
            try {
                $active = (new PrintessApi($serviceToken))->isSaveTokenActive($saveToken);
            } catch (\Throwable $e) {
                $this->logger->warning('Printess: save token status check failed', ['error' => $e->getMessage()]);
            }
        }

        if (!$active || !$product) {
            return $result->setData([
                'success'    => true,
                'active'     => false,
                'productUrl' => $product ? (string) $product->getProductUrl() : '',
                'message'    => __('This design is no longer available and needs to be recreated.'),
            ]);
        }

        return $result->setData([
            'success' => true,
            'active'  => true,
            'config'  => [
                'shopToken'           => $this->printessConfig->getShopToken(),
                'panelLoaderUrl'      => $this->printessConfig->getPanelLoaderUrl(),
                'templateName'        => $saveToken,
                'addToCartUrl'        => $this->urlBuilder->getUrl('checkout/cart/add', ['product' => (int) $product->getId()]),
                'productId'           => (string) $product->getId(),
                'productName'         => (string) $product->getName(),
                'productUrl'          => (string) $product->getProductUrl(),
                'formKey'             => $this->formKey->getFormKey(),
                'shopUserId'          => (string) $customerId,
                'variantOptions'      => $this->buildVariantOptions($product),
                'customOptions'       => $this->buildCustomOptions($product),
                'pagePricing'         => is_array($product->getData('printess_page_pricing'))
                    ? $product->getData('printess_page_pricing')
                    : $this->decodeJsonAttribute((string) $product->getData('printess_page_pricing')),
                'basePrice'           => (float) $product->getFinalPrice(),
                'currencyCode'        => (string) $product->getStore()->getCurrentCurrencyCode(),
                'locale'              => str_replace('_', '-', $this->localeResolver->getLocale()),
                'theme'               => (string) ($product->getData('printess_theme') ?: $this->printessConfig->getEditorTheme()),
                'magicPhotobookTheme' => (string) ($product->getData('printess_magic_photobook_theme') ?: ''),
                'printSettings'       => (string) ($product->getData('printess_print_settings') ?: $this->printessConfig->getPrintSettings()),
                'mergeTemplates'      => $this->buildMergeTemplates($product),
            ],
        ]);
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
