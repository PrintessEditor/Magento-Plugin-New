<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Project;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\UrlInterface;
use Printess\PrintessEditor\Helper\Config as PrintessConfig;
use Printess\PrintessEditor\Model\Project;

/**
 * Editor configuration for re-opening a saved project in the Panel editor (shape consumed by project-edit.js /
 * PrintessEditor.openFromProduct). Shared by "Continue Editing" and the supervisor review editor.
 */
class EditorConfigBuilder
{
    public function __construct(
        private readonly PrintessConfig $printessConfig,
        private readonly ResolverInterface $localeResolver,
        private readonly FormKey $formKey,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    /**
     * @param string|null $templateName save token to open (default: the project's)
     * @param int $shopUserId customer the editor runs for
     */
    public function build(Project $project, ProductInterface $product, int $shopUserId, ?string $templateName = null): array
    {
        $config = [
            'shopToken' => $this->printessConfig->getShopToken(),
            'panelLoaderUrl' => $this->printessConfig->getPanelLoaderUrl(),
            'templateName' => (string) $project->getData('save_token'),
            'saveUrl' => $this->urlBuilder->getUrl('printess/project/save'),
            'returnUrl' => $this->urlBuilder->getUrl('printess/project'),
            'projectId' => (int) $project->getId(),
            'projectName' => (string) $project->getData('name'),
            'productId' => (string) $product->getId(),
            'productName' => (string) $product->getName(),
            'productUrl' => (string) $product->getProductUrl(),
            'shopUserId' => (string) $shopUserId,
            'addToCartUrl' => $this->urlBuilder->getUrl('checkout/cart/add', ['product' => (int) $product->getId()]),
            'formKey' => $this->formKey->getFormKey(),
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
            'mergeTemplates' => array_values(array_map(
                static function (array $r): array {
                    $entry = ['templateName' => (string)($r['mergeTemplate'] ?? '')];
                    if (($r['mergeMode'] ?? '') !== '') { $entry['mergeMode'] = (string)$r['mergeMode']; }
                    return $entry;
                },
                array_filter(
                    (array) ($product->getData('printess_merge_template') ?: []),
                    static fn($r): bool => is_array($r) && trim((string)($r['mergeTemplate'] ?? '')) !== ''
                )
            ))
        ];
        if ($templateName !== null) {
            $config['templateName'] = $templateName;
        }
        return $config;
    }

    /**
     * Labels for a stored buy request: "super_attribute:<id>" / "options:<id>" => [label, values[id => label]].
     */
    public function buildOptionLabels(ProductInterface $product): array
    {
        $out = [];
        foreach ($this->buildVariantOptions($product) as $attr) {
            $out['super_attribute:' . $attr['attributeId']] = [
                'label'  => $attr['label'],
                'values' => array_flip($attr['options']),
            ];
        }
        foreach ($this->buildCustomOptions($product) as $opt) {
            $values = [];
            foreach ($opt['values'] as $v) {
                $values[$v['id']] = $v['label'];
            }
            $out['options:' . $opt['optionId']] = ['label' => $opt['title'], 'values' => $values];
        }
        return $out;
    }

    public function buildVariantOptions(\Magento\Catalog\Api\Data\ProductInterface $product): array
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

    public function buildCustomOptions(\Magento\Catalog\Api\Data\ProductInterface $product): array
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
