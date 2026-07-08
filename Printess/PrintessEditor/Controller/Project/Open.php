<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Project;

use Printess\PrintessEditor\Model\ProjectManager;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\UrlInterface;
use Printess\PrintessEditor\Helper\Config as PrintessConfig;

class Open extends Action implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly Session $customerSession,
        private readonly ProjectManager $projectManager,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly PrintessConfig $printessConfig,
        private readonly ResolverInterface $localeResolver,
        private readonly UrlInterface $urlBuilder,
        private readonly DateTime $dateTime
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        if (!$this->customerSession->isLoggedIn()) {
            return $result->setHttpResponseCode(401)->setData([
                'success' => false,
                'message' => __('You must be signed in to continue editing a Printess project.')
            ]);
        }

        try {
            $project = $this->projectManager->getOwnedProject(
                (int) $this->getRequest()->getParam('project_id'),
                (int) $this->customerSession->getCustomerId()
            );

            if ($this->isExpired($project)) {
                return $result->setData([
                    'success' => false,
                    'message' => __('This saved project has expired.')
                ]);
            }

            if (!$project->getData('product_id')) {
                return $result->setData([
                    'success' => false,
                    'message' => __('This project has no associated product and cannot be re-opened from here. Please visit the product page.')
                ]);
            }

            $product = $this->productRepository->getById((int) $project->getData('product_id'));
            if (trim((string) $product->getData('printess_template')) === '') {
                return $result->setData([
                    'success' => false,
                    'message' => __('This product does not support Printess projects.')
                ]);
            }

            return $result->setData([
                'success' => true,
                'config' => [
                    'shopToken' => $this->printessConfig->getShopToken(),
                    'templateName' => (string) $project->getData('save_token'),
                    'saveUrl' => $this->urlBuilder->getUrl('printess/project/save'),
                    'returnUrl' => $this->urlBuilder->getUrl('printess/project'),
                    'projectId' => (int) $project->getId(),
                    'productId' => (string) $product->getId(),
                    'variantOptions' => $this->buildVariantOptions($product),
                    'customOptions' => $this->buildCustomOptions($product),
                    'pagePricing' => is_array($product->getData('printess_page_pricing'))
                        ? $product->getData('printess_page_pricing')
                        : $this->decodeJsonAttribute((string) $product->getData('printess_page_pricing')),
                    'basePrice' => (float) $product->getFinalPrice(),
                    'currencyCode' => (string) $product->getStore()->getCurrentCurrencyCode(),
                    'locale' => str_replace('_', '-', $this->localeResolver->getLocale()),
                    'theme' => (string) ($product->getData('printess_theme') ?: $this->printessConfig->getEditorTheme()),
                    'magicPhotobookTheme' => (string) ($product->getData('printess_magic_photobook_theme') ?: ''),
                    'printSettings' => (string) ($product->getData('printess_print_settings') ?: $this->printessConfig->getPrintSettings()),
                    'mergeTemplate' => (string) ($product->getData('printess_merge_template') ?: '')
                ]
            ]);
        } catch (LocalizedException $exception) {
            return $result->setData([
                'success' => false,
                'message' => $exception->getMessage()
            ]);
        }
    }

    private function isExpired(\Printess\PrintessEditor\Model\Project $project): bool
    {
        $expiresAt = (string) $project->getData('expires_at');
        if ($expiresAt === '') {
            return false;
        }

        try {
            return (new \DateTimeImmutable($expiresAt, new \DateTimeZone('UTC')))->getTimestamp()
                <= $this->dateTime->gmtTimestamp();
        } catch (\Throwable) {
            return true;
        }
    }

    private function buildVariantOptions(\Magento\Catalog\Api\Data\ProductInterface $product): array
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

    private function buildCustomOptions(\Magento\Catalog\Api\Data\ProductInterface $product): array
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
                        'id' => (string) $_val->getOptionTypeId(),
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
