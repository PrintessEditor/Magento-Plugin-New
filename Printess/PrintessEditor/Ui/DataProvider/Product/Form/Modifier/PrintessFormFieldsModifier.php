<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Ui\DataProvider\Product\Form\Modifier;

use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\AbstractModifier;
use Magento\Framework\Stdlib\ArrayManager;

class PrintessFormFieldsModifier extends AbstractModifier
{
    public function __construct(private readonly ArrayManager $arrayManager) {}

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

        $meta = $this->arrayManager->set($path, $meta, [
            'arguments' => [
                'data' => [
                    'config' => [
                        'componentType'       => 'dynamicRows',
                        'label'               => __('Predefined Form Fields'),
                        'scopeLabel'          => __('[STORE VIEW]'),
                        'renderDefaultRecord' => false,
                        'recordTemplate'      => 'record',
                        'dndConfig'           => ['enabled' => false],
                        'additionalClasses'   => 'admin__field-wide',
                        'notice'              => __(
                            'Form field name/value pairs passed to the Printess editor when the product is opened. '
                            . 'These are static product-level defaults; values driven by variant or custom option selections are merged on top.'
                        ),
                    ],
                ],
            ],
            'children' => [
                'record' => [
                    'arguments' => [
                        'data' => [
                            'config' => [
                                'componentType' => 'container',
                                'isTemplate'    => true,
                                'is_collection' => true,
                                'headerLabel'   => '',
                            ],
                        ],
                    ],
                    'children' => [
                        'fieldName' => [
                            'arguments' => [
                                'data' => [
                                    'config' => [
                                        'componentType' => 'field',
                                        'formElement'   => 'input',
                                        'dataType'      => 'text',
                                        'label'         => __('Form Field Name'),
                                        'dataScope'     => 'fieldName',
                                        'sortOrder'     => 10,
                                        'fit'           => false,
                                        'validation'    => ['required-entry' => true],
                                    ],
                                ],
                            ],
                        ],
                        'fieldValue' => [
                            'arguments' => [
                                'data' => [
                                    'config' => [
                                        'componentType' => 'field',
                                        'formElement'   => 'input',
                                        'dataType'      => 'text',
                                        'label'         => __('Value'),
                                        'dataScope'     => 'fieldValue',
                                        'sortOrder'     => 20,
                                        'fit'           => false,
                                    ],
                                ],
                            ],
                        ],
                        'actionDelete' => [
                            'arguments' => [
                                'data' => [
                                    'config' => [
                                        'componentType' => 'actionDelete',
                                        'label'         => '',
                                        'sortOrder'     => 30,
                                        'fit'           => true,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        return $meta;
    }
}
