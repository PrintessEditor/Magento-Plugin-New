<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\CustomerFields;

/**
 * Printess form fields whose value is set per customer and store view by an admin (RMC-01).
 *
 * PURE: no Magento dependencies.
 */
final class FieldValue
{
    /** Hidden Printess form field with the customer's identifier in a store. */
    public const RM_CUST = 'RM_CUST';

    /** Fields an admin can set (the admin tab shows one column per field). */
    public const FIELDS = [self::RM_CUST];

    public const MAX_LENGTH = 255;

    /**
     * Trimmed value without control characters; '' means "not set".
     *
     * @throws \InvalidArgumentException when longer than MAX_LENGTH
     */
    public static function normalize(?string $value): string
    {
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $value));
        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf('A value can be at most %d characters long.', self::MAX_LENGTH));
        }
        return $value;
    }

    /**
     * Customer values win over the product's predefined / variant-derived form fields of a NEW design.
     *
     * @param list<array{name:string,value:string}> $formFields
     * @param array<string,string> $customerValues
     * @return list<array{name:string,value:string}>
     */
    public static function merge(array $formFields, array $customerValues): array
    {
        $map = [];
        foreach ($formFields as $field) {
            if (isset($field['name']) && $field['name'] !== '') {
                $map[(string) $field['name']] = (string) ($field['value'] ?? '');
            }
        }
        foreach ($customerValues as $name => $value) {
            if ($value !== '') {
                $map[(string) $name] = (string) $value;
            }
        }
        $out = [];
        foreach ($map as $name => $value) {
            $out[] = ['name' => $name, 'value' => $value];
        }
        return $out;
    }
}
