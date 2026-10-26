<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Ui\DataProvider\Product\Form\Modifier;

use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\AbstractModifier;
use Magento\Catalog\Model\Locator\LocatorInterface;
use Magento\Framework\Stdlib\ArrayManager;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;

class PrintessVariantSync extends AbstractModifier
{
    public function __construct(
        private readonly LocatorInterface $locator,
        private readonly ArrayManager $arrayManager
    ) {
    }

    public function modifyData(array $data): array
    {
        return $data;
    }

    public function modifyMeta(array $meta): array
    {
        $product        = $this->locator->getProduct();
        $selectOptions  = [['value' => '', 'label' => __('-- None --')]];
        $allOptions     = [];

        if ($product->getTypeId() === Configurable::TYPE_CODE) {
            foreach ($product->getTypeInstance()->getConfigurableAttributes($product) as $attr) {
                $pa = $attr->getProductAttribute();
                if (!$pa) {
                    continue;
                }
                $code           = $pa->getAttributeCode();
                $selectOptions[] = ['value' => $code, 'label' => $pa->getFrontendLabel() ?: $code];
                $labels = [];
                foreach ((array)$attr->getOptions() as $opt) {
                    $l = (string)($opt['label'] ?? '');
                    if ($l !== '') {
                        $labels[] = $l;
                    }
                }
                $allOptions[$code] = $labels;
            }
        }

        // Change printess_variant_attribute from text input to select
        $attrPath = $this->arrayManager->findPath('printess_variant_attribute', $meta, null, 'children');
        if ($attrPath !== null) {
            $meta = $this->arrayManager->merge($attrPath . '/arguments/data/config', $meta, [
                'formElement' => 'select',
                'options'     => $selectOptions,
                'notice'      => __('The configurable attribute whose selected value is synced to the Printess FormField.'),
            ]);
        }

        // Inject the options table HTML into the container
        $containerPath = $this->arrayManager->findPath(
            'container_printess_variant_attribute',
            $meta,
            null,
            'children'
        );
        if ($containerPath !== null && !empty($allOptions)) {
            $meta = $this->arrayManager->set(
                $containerPath . '/children/printess_variant_options_table',
                $meta,
                [
                    'arguments' => ['data' => ['config' => [
                        'componentType' => 'container',
                        'component'     => 'Magento_Ui/js/form/components/html',
                        'content'       => $this->buildTablesHtml($allOptions),
                        'sortOrder'     => 999,
                    ]]],
                ]
            );
        }

        // Update printess_variant_field label/notice (keep it visible for manual override)
        $fieldPath = $this->arrayManager->findPath('printess_variant_field', $meta, null, 'children');
        if ($fieldPath !== null) {
            $meta = $this->arrayManager->merge($fieldPath . '/arguments/data/config', $meta, [
                'label'  => __('Printess FormField Name'),
                'notice' => __('The FormField name used in Printess. Leave empty to use the Magento attribute code.'),
            ]);
        }

        return $meta;
    }

    private function buildTablesHtml(array $allOptions): string
    {
        // phpcs:disable Magento2.Functions.DiscouragedFunction -- Escaper not available in UI modifier context
        $html = '<div id="printess-variant-options-wrapper">';
        foreach ($allOptions as $code => $labels) {
            $rows = implode('', array_map(
                static fn($l) => '<tr><td style="padding:4px 8px;">' . htmlspecialchars((string)$l, ENT_QUOTES, 'UTF-8') . '</td></tr>',
                $labels
            ));
            $html .= sprintf(
                '<div class="printess-opts-table" data-attr="%s" style="display:none;margin-top:6px;">'
                . '<table class="data-grid" style="width:auto;min-width:200px;">'
                . '<thead><tr><th style="padding:4px 8px;">%s</th></tr></thead>'
                . '<tbody>%s</tbody>'
                . '</table></div>',
                htmlspecialchars((string)$code, ENT_QUOTES, 'UTF-8'),
                __('Option Values'),
                $rows
            );
        }
        // phpcs:enable
        $html .= '</div>';
        return $html;
    }
}
