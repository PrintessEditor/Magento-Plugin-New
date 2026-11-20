<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\CustomerFields;

/**
 * UDP-01: account data a product can prefill into Printess form fields ("Prefill user data").
 *
 * PURE: no Magento dependencies. UserDataProvider turns the customer and the default billing address into the
 * flat input of values(); the product's mapping (printess_user_data_map) picks which source goes into which field.
 */
final class UserDataSource
{
    public const FIRST_NAME    = 'first_name';
    public const LAST_NAME     = 'last_name';
    public const FULL_NAME     = 'full_name';
    public const EMAIL         = 'email';
    public const PHONE         = 'phone';
    public const COMPANY       = 'company';
    public const STREET        = 'street';
    public const STREET_1      = 'street_line_1';
    public const STREET_2      = 'street_line_2';
    public const POSTCODE      = 'postcode';
    public const CITY          = 'city';
    public const REGION        = 'region';
    public const COUNTRY       = 'country';
    public const COUNTRY_CODE  = 'country_code';
    public const ADDRESS_BLOCK = 'address_block';

    /** source => admin label (English; translated in the admin) */
    public const LABELS = [
        self::FIRST_NAME    => 'First name',
        self::LAST_NAME     => 'Last name',
        self::FULL_NAME     => 'Full name (first + last)',
        self::EMAIL         => 'Email',
        self::PHONE         => 'Phone (billing address)',
        self::COMPANY       => 'Company (billing address)',
        self::STREET        => 'Street, all lines (billing address)',
        self::STREET_1      => 'Street line 1 (billing address)',
        self::STREET_2      => 'Street line 2 (billing address)',
        self::POSTCODE      => 'Postcode (billing address)',
        self::CITY          => 'City (billing address)',
        self::REGION        => 'State/Province (billing address)',
        self::COUNTRY       => 'Country (billing address)',
        self::COUNTRY_CODE  => 'Country code, e.g. DE (billing address)',
        self::ADDRESS_BLOCK => 'Full address, multi-line (billing address)',
    ];

    public static function isKnown(string $source): bool
    {
        return isset(self::LABELS[$source]);
    }

    /**
     * Cleans the stored mapping: rows with an empty field name or an unknown source are dropped, a field name is kept
     * once (last row wins).
     *
     * @param mixed $value array of {fieldName, source} rows or its JSON
     * @return list<array{fieldName: string, source: string}>
     */
    public static function normalizeMap(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        $byName = [];
        foreach (is_array($value) ? $value : [] as $row) {
            if (!is_array($row) || !empty($row['delete'])) {
                continue;
            }
            $name = trim((string) ($row['fieldName'] ?? ''));
            $source = trim((string) ($row['source'] ?? ''));
            if ($name === '' || !self::isKnown($source)) {
                continue;
            }
            unset($byName[$name]);
            $byName[$name] = ['fieldName' => $name, 'source' => $source];
        }
        return array_values($byName);
    }

    /**
     * @param array{firstname?: ?string, lastname?: ?string, email?: ?string} $customer
     * @param array{company?: ?string, street?: string[]|string|null, postcode?: ?string, city?: ?string,
     *              region?: ?string, country_id?: ?string, country_name?: ?string, telephone?: ?string}|null $address
     *        default billing address, null when the customer has none
     * @return array<string, string> source => value ('' when unknown)
     */
    public static function values(array $customer, ?array $address): array
    {
        $first = self::clean($customer['firstname'] ?? '');
        $last = self::clean($customer['lastname'] ?? '');
        $address = $address ?? [];
        $street = $address['street'] ?? [];
        $lines = array_values(array_filter(
            array_map(
                static fn (mixed $l): string => self::clean($l),
                is_array($street) ? $street : explode("\n", (string) $street)
            ),
            static fn (string $l): bool => $l !== ''
        ));
        $postcode = self::clean($address['postcode'] ?? '');
        $city = self::clean($address['city'] ?? '');
        $country = self::clean($address['country_name'] ?? '') ?: self::clean($address['country_id'] ?? '');
        $company = self::clean($address['company'] ?? '');
        $region = self::clean($address['region'] ?? '');

        $block = array_filter([
            $company,
            ...$lines,
            trim($postcode . ' ' . $city),
            $country,
        ], static fn (string $l): bool => $l !== '');

        return [
            self::FIRST_NAME    => $first,
            self::LAST_NAME     => $last,
            self::FULL_NAME     => trim($first . ' ' . $last),
            self::EMAIL         => self::clean($customer['email'] ?? ''),
            self::PHONE         => self::clean($address['telephone'] ?? ''),
            self::COMPANY       => $company,
            self::STREET        => implode(', ', $lines),
            self::STREET_1      => $lines[0] ?? '',
            self::STREET_2      => $lines[1] ?? '',
            self::POSTCODE      => $postcode,
            self::CITY          => $city,
            self::REGION        => $region,
            self::COUNTRY       => $country,
            self::COUNTRY_CODE  => strtoupper(self::clean($address['country_id'] ?? '')),
            self::ADDRESS_BLOCK => implode("\n", $block),
        ];
    }

    /**
     * Form fields for the editor; fields whose value is empty are left out so the product's own value stays.
     *
     * @param list<array{fieldName: string, source: string}> $map
     * @param array<string, string> $values from values()
     * @return list<array{name: string, value: string}>
     */
    public static function resolve(array $map, array $values): array
    {
        $out = [];
        foreach ($map as $row) {
            $value = $values[$row['source']] ?? '';
            if ($value !== '') {
                $out[] = ['name' => $row['fieldName'], 'value' => $value];
            }
        }
        return $out;
    }

    private static function clean(mixed $value): string
    {
        return trim((string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', (string) $value));
    }
}
