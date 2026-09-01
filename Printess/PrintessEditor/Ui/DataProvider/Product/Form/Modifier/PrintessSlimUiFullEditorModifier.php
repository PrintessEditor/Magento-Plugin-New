<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Ui\DataProvider\Product\Form\Modifier;

use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\AbstractModifier;
use Magento\Framework\Stdlib\ArrayManager;

/**
 * Keeps "Allow Switch to Full Editor" (printess_slim_ui_full_editor) disabled -- visible but
 * non-interactive, not hidden -- until "Use Printess Slim UI" (printess_slim_ui) is checked,
 * since the setting has no effect on a Panel UI product. Also nudges its sort order to sit
 * right after the Slim UI checkbox, so the two line up in DOM order for the CSS-driven
 * side-by-side pairing (see view/adminhtml/web/css/admin-product-form.css).
 *
 * Layout note: the two fields are placed side by side purely via CSS, targeting their stable
 * `data-index` attribute (set by Magento_Ui's ui/form/field.html for every field), NOT via
 * Magento_Ui's "group" component. An earlier version of this modifier used the group component
 * (the same mechanism core Magento uses to lay out a WYSIWYG attribute's field next to its
 * "Insert Image" button), but that component's own template
 * (Magento_Ui/view/base/web/templates/group/group.html) has a long-standing core bug for
 * checkbox/radio children: its two <if> conditions choosing between the group's shared field
 * template and the child element's own bare template both evaluate true for a checkbox/radio
 * element --
 *   input_type != 'checkbox' || input_type != 'radio'   (always true, for either value)
 *   input_type == 'checkbox' || input_type == 'radio'   (also true for either value)
 * -- so both branches render for a checkbox child, and the field's label (only present on the
 * first branch's template) is lost or obscured by the duplicate. Plain per-field CSS avoids
 * that codepath entirely and leaves each field's normal (and correctly labelled) rendering
 * untouched.
 */
class PrintessSlimUiFullEditorModifier extends AbstractModifier
{
    public function __construct(private readonly ArrayManager $arrayManager) {}

    public function modifyData(array $data): array
    {
        return $data;
    }

    public function modifyMeta(array $meta): array
    {
        $slimUiFieldPath = $this->arrayManager->findPath('printess_slim_ui', $meta, null, 'children');
        $fullEditorFieldPath = $this->arrayManager->findPath('printess_slim_ui_full_editor', $meta, null, 'children');

        if ($slimUiFieldPath === null || $fullEditorFieldPath === null) {
            return $meta;
        }

        // Reactive: disabled whenever the Slim UI checkbox is unchecked, live, no page reload.
        // The leading "!" negates the imported value (see Magento_Ui's links.js transfer()).
        $meta = $this->arrayManager->merge($fullEditorFieldPath . '/arguments/data/config', $meta, [
            'imports' => [
                'disabled' => '!${ $.provider }:data.product.printess_slim_ui',
            ],
            'notice' => __('Only takes effect when "Use Printess Slim UI" is enabled.'),
        ]);

        // Order it right after the Slim UI checkbox, whatever the Slim UI checkbox's own
        // auto-generated sortOrder happens to be.
        $slimUiSortOrder = (int) ($this->arrayManager->get(
            $slimUiFieldPath . '/arguments/data/config/sortOrder',
            $meta
        ) ?? 10);
        $meta = $this->arrayManager->merge($fullEditorFieldPath . '/arguments/data/config', $meta, [
            'sortOrder' => $slimUiSortOrder + 10,
        ]);

        return $meta;
    }
}
