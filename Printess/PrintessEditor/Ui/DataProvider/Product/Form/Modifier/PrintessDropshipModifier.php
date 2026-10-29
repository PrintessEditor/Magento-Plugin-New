<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Ui\DataProvider\Product\Form\Modifier;

use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\AbstractModifier;
use Magento\Framework\Stdlib\ArrayManager;
use Printess\PrintessEditor\Model\Config\Source\DropshipProductDefinitions;
use Printess\PrintessEditor\Model\Production\DropshipMode;

/**
 * Product-form wiring for the dropship attributes:
 *  - "Dropship Product Definition" is disabled (visible, not hidden -- same approach as
 *    PrintessSlimUiFullEditorModifier) unless "Dropshipping" is set to "dropshipping", live via the mode field's switcher.
 *  - Its options are replaced with the grouped list so the admin gets one <optgroup> per dropshipper;
 *    the EAV default is the flat "Dropshipper › Definition" list.
 *  - The mode field gets a notice that dropshipping is set per product (empty = no dropshipping) and
 *    that template mode depends on the Printess template's own setup.
 */
class PrintessDropshipModifier extends AbstractModifier
{
    private const MODE_FIELD       = 'printess_dropship_mode';
    private const DEFINITION_FIELD = 'printess_dropship_product_definition';

    public function __construct(
        private readonly ArrayManager $arrayManager,
        private readonly DropshipProductDefinitions $definitions
    ) {
    }

    public function modifyData(array $data): array
    {
        return $data;
    }

    public function modifyMeta(array $meta): array
    {
        $modePath       = $this->arrayManager->findPath(self::MODE_FIELD, $meta, null, 'children');
        $definitionPath = $this->arrayManager->findPath(self::DEFINITION_FIELD, $meta, null, 'children');

        if ($modePath === null) {
            return $meta;
        }

        // The notice is set here in full instead of relying on the attribute's stored note: eav_attribute.note
        // is a varchar(255), so longer notes get cut off, and notes written by older patches can be stale.
        $notice = (string) __(
            'Dropshipping is set per product: choose the dropshipper and product below. "No dropshipping" (not set) '
            . 'produces a print PDF; "Never dropship" is an explicit opt-out. For configurable products the simple '
            . 'child product decides first; a child without a setting follows its parent product. "Use the '
            . 'dropshipping set up on the Printess template" only works if a product definition is configured on '
            . 'the Printess template itself.'
        );
        $meta = $this->arrayManager->set($modePath . '/arguments/data/config/notice', $meta, $notice);

        if ($definitionPath === null) {
            return $meta;
        }

        // SET, not merge: merging appends the grouped list to the attribute's flat EAV options, which listed
        // every definition twice.
        $meta = $this->arrayManager->set(
            $definitionPath . '/arguments/data/config/options',
            $meta,
            $this->definitions->toOptionArray()
        );
        // The grouped options already start with "-- Select a product definition --".
        $meta = $this->arrayManager->set($definitionPath . '/arguments/data/config/caption', $meta, '');

        // Reactive enable/disable. Unlike the Slim UI checkbox this depends on a select's value
        // being equal to 'dropshipping', which a plain imports link (copy or "!" negate) cannot
        // express, so the mode field's built-in switcher (Magento_Ui/js/form/switcher, enabled via
        // switcherConfig on any form element) calls enable()/disable() on the definition field.
        $target = static::FORM_NAME . '.' . static::FORM_NAME . '.'
            . str_replace('/children/', '.', $definitionPath);
        $rules = [];
        // '' (not set / "No dropshipping") is the attribute default.
        foreach (['', DropshipMode::NONE, DropshipMode::TEMPLATE] as $value) {
            $rules[] = ['value' => $value, 'actions' => [['target' => $target, 'callback' => 'disable']]];
        }
        $rules[] = [
            'value'   => DropshipMode::DROPSHIPPING,
            'actions' => [['target' => $target, 'callback' => 'enable']],
        ];

        $meta = $this->arrayManager->merge($modePath . '/arguments/data/config', $meta, [
            'switcherConfig' => [
                'enabled' => true,
                'rules'   => $rules,
            ],
        ]);

        return $meta;
    }
}
