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
