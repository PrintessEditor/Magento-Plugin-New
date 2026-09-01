<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Ui\DataProvider\Product\Form\Modifier;

use Magento\Backend\Model\UrlInterface;
use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\AbstractModifier;
use Magento\Framework\Stdlib\ArrayManager;

class PrintessFormFieldsModifier extends AbstractModifier
{
    public function __construct(
        private readonly ArrayManager $arrayManager,
        private readonly UrlInterface $backendUrl
    ) {}

    public function modifyData(array $data): array
    {
        foreach ($data as $productId => &$productData) {
            $val = $productData[self::DATA_SOURCE_DEFAULT]['printess_form_fields'] ?? null;
            if (is_string($val)) {
                $decoded = json_decode($val, true);
                $productData[self::DATA_SOURCE_DEFAULT]['printess_form_fields'] = is_array($decoded) ? $decoded : [];
            } elseif (!is_array($val)) {
                $productData[self::DATA_SOURCE_DEFAULT]['printess_form_fields'] = [];
            }
        }
        unset($productData);
        return $data;
    }

    public function modifyMeta(array $meta): array
    {
        $path = $this->arrayManager->findPath('printess_form_fields', $meta, null, 'children');
        if ($path === null) {
            return $meta;
        }

        $meta = $this->arrayManager->merge(
            $path . '/arguments/data/config',
            $meta,
            [
                'componentType'      => 'field',
                'formElement'        => 'input',
                'component'          => 'Printess_PrintessEditor/js/product/form/element/form-fields-editor',
                'elementTmpl'        => 'Printess_PrintessEditor/product/form/element/form-fields-editor',
                'endpointFormFields' => $this->backendUrl->getUrl('printess/api/formfields'),
                'additionalClasses'  => 'admin__field-wide',
                'notice'             => __(
                    'Form field name/value pairs passed to the Printess editor when the product is opened. '
                    . 'These are static product-level defaults; values driven by variant or custom option selections are merged on top.'
                ),
            ]
        );

        return $meta;
    }
}
