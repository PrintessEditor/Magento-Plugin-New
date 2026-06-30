<?php
namespace Printess\PrintessEditor\Ui\DataProvider\Product\Form\Modifier;

use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\AbstractModifier;
use Magento\Framework\Stdlib\ArrayManager;

class PrintessPricingModifier extends AbstractModifier
{
    public function __construct(private readonly ArrayManager $arrayManager) {}

    public function modifyData(array $data): array
    {
        foreach ($data as $productId => &$productData) {
            $val = $productData[self::DATA_SOURCE_DEFAULT]['printess_page_pricing'] ?? null;
            if (is_string($val)) {
                $decoded = json_decode($val, true);
                $productData[self::DATA_SOURCE_DEFAULT]['printess_page_pricing'] = is_array($decoded) ? $decoded : [];
            } elseif (!is_array($val)) {
                $productData[self::DATA_SOURCE_DEFAULT]['printess_page_pricing'] = [];
            }
        }
        unset($productData);
        return $data;
    }

    public function modifyMeta(array $meta): array
    {
        foreach (['printess_price_per_page', 'printess_variant_field', 'printess_variant_attribute'] as $attrCode) {
            $path = $this->arrayManager->findPath($attrCode, $meta, null, 'children');
            if ($path !== null) {
                $meta = $this->arrayManager->merge($path . '/arguments/data/config', $meta, ['visible' => false]);
            }
        }

        $path = $this->arrayManager->findPath('printess_page_pricing', $meta, null, 'children');
        if ($path === null) {
            return $meta;
        }

        $meta = $this->arrayManager->set($path, $meta, [
            'arguments' => [
                'data' => [
                    'config' => [
                        'componentType'       => 'dynamicRows',
                        'label'               => __('Page Pricing Rules'),
                        'renderDefaultRecord' => false,
                        'recordTemplate'      => 'record',
                        'dndConfig'           => ['enabled' => false],
                        'additionalClasses'   => 'admin__field-wide',
                        'notice'              => __(
                            'Price per page based on Printess form field values. '
                            . 'Leave conditions empty for the default/fallback price.'
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
                        'conditions' => [
                            'arguments' => [
                                'data' => [
                                    'config' => [
                                        'componentType' => 'field',
                                        'formElement'   => 'input',
                                        'dataType'      => 'text',
                                        'label'         => __('Conditions'),
                                        'notice'        => __('fieldName=value,field2=value2  (empty = default)'),
                                        'dataScope'     => 'conditions',
                                        'sortOrder'     => 10,
                                        'fit'           => false,
                                    ],
                                ],
                            ],
                        ],
                        'pricePerPage' => [
                            'arguments' => [
                                'data' => [
                                    'config' => [
                                        'componentType' => 'field',
                                        'formElement'   => 'input',
                                        'dataType'      => 'number',
                                        'label'         => __('Price Per Page'),
                                        'dataScope'     => 'pricePerPage',
                                        'sortOrder'     => 20,
                                        'fit'           => false,
                                        'validation'    => ['validate-number' => true],
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
