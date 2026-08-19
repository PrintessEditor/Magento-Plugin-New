<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Ui\DataProvider\Product\Form\Modifier;

use Magento\Backend\Model\UrlInterface;
use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\AbstractModifier;
use Magento\Framework\Stdlib\ArrayManager;

class PrintessTemplateModifier extends AbstractModifier
{
    public function __construct(
        private readonly ArrayManager $arrayManager,
        private readonly UrlInterface $backendUrl
    ) {}

    public function modifyData(array $data): array
    {
        foreach ($data as $productId => &$productData) {
            $val = $productData[self::DATA_SOURCE_DEFAULT]['printess_merge_template'] ?? null;
            if (is_string($val) && $val !== '') {
                // Decode JSON array or migrate legacy single string
                $decoded = json_decode($val, true);
                $productData[self::DATA_SOURCE_DEFAULT]['printess_merge_template'] =
                    is_array($decoded) ? $decoded : [['mergeTemplate' => $val]];
            } elseif (!is_array($val)) {
                $productData[self::DATA_SOURCE_DEFAULT]['printess_merge_template'] = [];
            }
        }
        unset($productData);
        return $data;
    }

    public function modifyMeta(array $meta): array
    {
        $pickerConfig = $this->pickerComponentConfig();

        // ── Main template field (templates only — no snippets tab) ──────
        $templatePath = $this->arrayManager->findPath('printess_template', $meta, null, 'children');
        if ($templatePath !== null) {
            $meta = $this->arrayManager->merge(
                $templatePath . '/arguments/data/config',
                $meta,
                array_merge($pickerConfig, ['showSnippetsTab' => false])
            );
        }

        // ── Merge template: hide auto-generated field, add own fieldset ─
        $mergePath = $this->arrayManager->findPath('printess_merge_template', $meta, null, 'children');
        if ($mergePath !== null) {
            // The path is: …/GroupName/children/container_printess_merge_template/children/printess_merge_template
            $parts             = explode('/', $mergePath);
            $groupChildrenPath = implode('/', array_slice($parts, 0, -3));   // …/GroupName/children
            $containerPath     = implode('/', array_slice($parts, 0, -2));   // …/container_printess_merge_template

            // Hide the original container (and the field inside it)
            $meta = $this->arrayManager->merge(
                $containerPath . '/arguments/data/config',
                $meta,
                ['visible' => false, 'disabled' => true]
            );

            // Insert a separate fieldset with a dynamic list of merge templates
            $meta = $this->arrayManager->set(
                $groupChildrenPath . '/printess_merge_fieldset',
                $meta,
                [
                    'arguments' => [
                        'data' => [
                            'config' => [
                                'componentType' => 'fieldset',
                                'label'         => __('Merge Templates'),
                                'collapsible'   => false,
                                'sortOrder'     => 195,
                            ],
                        ],
                    ],
                    'children' => [
                        'printess_merge_templates_grid' => [
                            'arguments' => [
                                'data' => [
                                    'config' => [
                                        'componentType'       => 'dynamicRows',
                                        'label'               => __('Merge Templates'),
                                        'renderDefaultRecord' => false,
                                        'recordTemplate'      => 'record',
                                        'dataScope'           => 'printess_merge_template',
                                        'dndConfig'           => ['enabled' => false],
                                        'additionalClasses'   => 'admin__field-wide',
                                        'addButton'           => true,
                                        'addButtonLabel'      => __('Add Merge Template'),
                                        'sortOrder'           => 10,
                                        'notice'              => __(
                                            'Optional. Each template is layered on top of the main template during production, in order.'
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
                                        'mergeTemplate' => [
                                            'arguments' => [
                                                'data' => [
                                                    'config' => array_merge(
                                                        [
                                                            'componentType' => 'field',
                                                            'formElement'   => 'input',
                                                            'dataType'      => 'text',
                                                            'label'         => __('Template'),
                                                            'dataScope'     => 'mergeTemplate',
                                                            'sortOrder'     => 10,
                                                            'fit'           => false,
                                                        ],
                                                        $pickerConfig
                                                    ),
                                                ],
                                            ],
                                        ],
                                        'mergeMode' => [
                                            'arguments' => [
                                                'data' => [
                                                    'config' => [
                                                        'componentType'    => 'field',
                                                        'formElement'      => 'select',
                                                        'dataType'         => 'text',
                                                        'label'            => __('Merge Mode'),
                                                        'dataScope'        => 'mergeMode',
                                                        'sortOrder'        => 20,
                                                        'fit'              => false,
                                                        'additionalClasses' => 'ptp-col-half',
                                                        'options'          => [
                                                            ['value' => '',                                                   'label' => __('Default')],
                                                            ['value' => 'merge',                                              'label' => __('Merge')],
                                                            ['value' => 'layout-snippet-no-repeat',                          'label' => __('Layout Snippet – No Repeat')],
                                                            ['value' => 'layout-snippet-repeat-all',                         'label' => __('Layout Snippet – Repeat All')],
                                                            ['value' => 'layout-snippet-repeat-inside',                      'label' => __('Layout Snippet – Repeat Inside')],
                                                            ['value' => 'layout-snippet-no-repeat-persist-stickers',         'label' => __('Layout Snippet – No Repeat (Persist Stickers)')],
                                                            ['value' => 'layout-snippet-repeat-all-persist-stickers',        'label' => __('Layout Snippet – Repeat All (Persist Stickers)')],
                                                            ['value' => 'layout-snippet-repeat-inside-persist-stickers',     'label' => __('Layout Snippet – Repeat Inside (Persist Stickers)')],
                                                        ],
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
                        ],
                    ],
                ]
            );
        }

        return $meta;
    }

    private function pickerComponentConfig(): array
    {
        return [
            'component'   => 'Printess_PrintessEditor/js/product/form/element/template-picker',
            'elementTmpl' => 'Printess_PrintessEditor/product/form/element/template-picker',
            'endpointTemplates'   => $this->backendUrl->getUrl('printess/api/templates'),
            'endpointDirectories' => $this->backendUrl->getUrl('printess/api/directories'),
            'endpointTags'        => $this->backendUrl->getUrl('printess/api/tags'),
            'endpointKeywords'    => $this->backendUrl->getUrl('printess/api/keywords'),
            'endpointSnippets'    => $this->backendUrl->getUrl('printess/api/snippets'),
        ];
    }
}
