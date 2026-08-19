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

            // Insert a separate fieldset for merge template
            $meta = $this->arrayManager->set(
                $groupChildrenPath . '/printess_merge_fieldset',
                $meta,
                [
                    'arguments' => [
                        'data' => [
                            'config' => [
                                'componentType' => 'fieldset',
                                'label'         => __('Merge Template'),
                                'collapsible'   => false,
                                'sortOrder'     => 195,
                            ],
                        ],
                    ],
                    'children' => [
                        'printess_merge_template' => [
                            'arguments' => [
                                'data' => [
                                    'config' => array_merge(
                                        [
                                            'componentType' => 'field',
                                            'formElement'   => 'input',
                                            'dataType'      => 'text',
                                            'label'         => __('Merge Template'),
                                            'dataScope'     => 'printess_merge_template',
                                            'sortOrder'     => 10,
                                            'notice'        => __(
                                                'Optional. Layered on top of the main template during production.'
                                            ),
                                        ],
                                        $pickerConfig
                                    ),
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
