<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Reorder;

/**
 * f22: when an ordered design (save token) has expired, "Edit & Reorder" offers a NEW design on the product's
 * template, preset with everything the old order still knows. Pure: no Magento dependencies.
 *
 * Form fields for the editor, lowest priority first:
 *  1. the product's predefined form fields (`printess_form_fields`, rows {fieldName, fieldValue});
 *  2. the editor's form field values at add-to-cart (`printessFormFields` in the order item's info_buyRequest);
 *  3. the chosen custom options (drop-down / radio, matched to a form field by option title) and the configurable
 *     variant (matched by attribute label): the old order's selections, the same mapping the product page uses.
 *
 * Selections for the add-to-cart form: `super_attribute` / `options` ids from the buy request, limited to the
 * product's current attributes and option values, so a cart line matches the old one unless the customer changes
 * a mapped field in the editor.
 */
final class NewDesignPreset
{
    /**
     * @param array<int, mixed> $productFormFields `printess_form_fields` rows
     * @param array<string, mixed> $buyRequest the old order item's info_buyRequest
     * @param list<array{title: string, optionId: string, values: list<array{label: string, id: string}>}> $customOptions
     * @param list<array{label: string, attributeId: string, options: array<string, string>}> $variantOptions label => value id
     * @return list<array{name: string, value: string}>
     */
    public static function formFields(
        array $productFormFields,
        array $buyRequest,
        array $customOptions,
        array $variantOptions
    ): array {
        $map = [];

        foreach ($productFormFields as $row) {
            if (is_array($row) && is_scalar($row['fieldName'] ?? null) && trim((string) $row['fieldName']) !== '') {
                $map[trim((string) $row['fieldName'])] = is_scalar($row['fieldValue'] ?? null) ? (string) $row['fieldValue'] : '';
            }
        }

        $raw = $buyRequest['printessFormFields'] ?? null;
        $editorFields = is_array($raw) ? $raw : (is_string($raw) ? json_decode($raw, true) : null);
        if (is_array($editorFields)) {
            foreach ($editorFields as $name => $value) {
                if (is_string($name) && trim($name) !== '' && is_scalar($value)) {
                    $map[trim($name)] = (string) $value;
                }
            }
        }

        $selections = self::selections($buyRequest, $customOptions, $variantOptions);
        foreach ($customOptions as $opt) {
            $chosen = $selections['options'][(string) $opt['optionId']] ?? null;
            foreach ($chosen !== null ? $opt['values'] : [] as $value) {
                if ((string) $value['id'] === $chosen && (string) $opt['title'] !== '') {
                    $map[(string) $opt['title']] = (string) $value['label'];
                }
            }
        }
        foreach ($variantOptions as $attr) {
            $chosen = $selections['super_attribute'][(string) $attr['attributeId']] ?? null;
            $label = $chosen !== null ? array_search($chosen, array_map('strval', $attr['options']), true) : false;
            if ($label !== false && (string) $attr['label'] !== '') {
                $map[(string) $attr['label']] = (string) $label;
            }
        }

        $fields = [];
        foreach ($map as $name => $value) {
            $fields[] = ['name' => (string) $name, 'value' => $value];
        }
        return $fields;
    }

    /**
     * @param array<string, mixed> $buyRequest
     * @param list<array{title: string, optionId: string, values: list<array{label: string, id: string}>}> $customOptions
     * @param list<array{label: string, attributeId: string, options: array<string, string>}> $variantOptions
     * @return array{super_attribute: array<string, string>, options: array<string, string>}
     */
    public static function selections(array $buyRequest, array $customOptions, array $variantOptions): array
    {
        $result = ['super_attribute' => [], 'options' => []];

        $options = is_array($buyRequest['options'] ?? null) ? $buyRequest['options'] : [];
        foreach ($customOptions as $opt) {
            $chosen = $options[(string) $opt['optionId']] ?? null;
            if (!is_scalar($chosen)) {
                continue;
            }
            foreach ($opt['values'] as $value) {
                if ((string) $value['id'] === (string) $chosen) {
                    $result['options'][(string) $opt['optionId']] = (string) $chosen;
                }
            }
        }

        $super = is_array($buyRequest['super_attribute'] ?? null) ? $buyRequest['super_attribute'] : [];
        foreach ($variantOptions as $attr) {
            $chosen = $super[(string) $attr['attributeId']] ?? null;
            if (is_scalar($chosen) && in_array((string) $chosen, array_map('strval', $attr['options']), true)) {
                $result['super_attribute'][(string) $attr['attributeId']] = (string) $chosen;
            }
        }

        return $result;
    }
}
