<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Product;

/** Converts legacy attribute values and admin rows to Printess attach parameters. */
class EditorSettings
{
    public static function formFields($value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        $fields = [];
        foreach (is_array($value) ? $value : [] as $row) {
            if (!is_array($row) || !empty($row['delete'])) {
                continue;
            }
            $name = trim((string) ($row['fieldName'] ?? ''));
            if ($name !== '') {
                $fields[] = ['name' => $name, 'value' => (string) ($row['fieldValue'] ?? '')];
            }
        }
        return $fields;
    }
}
