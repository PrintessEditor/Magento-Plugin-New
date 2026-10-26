<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Product;

/** Match editor field names to Magento titles using the frontend's normalisation. */
class FormFieldValues
{
    public static function get(array $fields, string $name): ?string
    {
        if (array_key_exists($name, $fields)) {
            return (string)$fields[$name];
        }

        $normalised = self::normalise($name);
        foreach ($fields as $key => $value) {
            if (self::normalise((string)$key) === $normalised) {
                return (string)$value;
            }
        }

        return null;
    }

    private static function normalise(string $name): string
    {
        return trim((string)preg_replace('/[^A-Z0-9]+/', '_', mb_strtoupper(trim($name))), '_');
    }
}
