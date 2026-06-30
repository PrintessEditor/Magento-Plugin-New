<?php
namespace Printess\PrintessEditor\Ui\DataProvider\Product\Form\Modifier;

use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\AbstractModifier;
use Magento\Framework\Stdlib\ArrayManager;
use Printess\PrintessEditor\Model\Config\Source\EditorTheme;
use Printess\PrintessEditor\Model\Config\Source\MagicPhotobookTheme;

class PrintessThemeModifier extends AbstractModifier
{
    public function __construct(
        private readonly ArrayManager       $arrayManager,
        private readonly EditorTheme        $editorThemeSource,
        private readonly MagicPhotobookTheme $magicPhotobookThemeSource
    ) {}

    public function modifyData(array $data): array
    {
        return $data;
    }

    public function modifyMeta(array $meta): array
    {
        $fields = [
            'printess_theme'                => $this->editorThemeSource,
            'printess_magic_photobook_theme' => $this->magicPhotobookThemeSource,
        ];

        foreach ($fields as $attrCode => $source) {
            $path = $this->arrayManager->findPath($attrCode, $meta, null, 'children');
            if ($path === null) {
                continue;
            }
            $meta = $this->arrayManager->merge($path . '/arguments/data/config', $meta, [
                'formElement' => 'select',
                'component'   => 'Magento_Ui/js/form/element/select',
                'elementTmpl' => 'ui/form/element/select',
                'options'     => $source->getAllOptions(),
            ]);
        }

        return $meta;
    }
}
