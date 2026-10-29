<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Production;

/**
 * PURE. Maps a Magento order address (as a plain array) to the `json` object of Printess'
 * /dropshipData/save. Printess does not validate the address on save — a bad address only fails later
 * at the dropshipper — so values are trimmed and empty optional fields are left out rather than sent
 * as blanks the partner might reject.
 *
 * Input keys: firstname, lastname, company, street (list of lines, or a "\n"-separated string), city,
 * postcode, country_id, region_code, telephone.
 *
 * `$dispatchNotice` (optional) is the customer's order comment; it is sent as `dispatchNotice` so the dropshipper
 * sees it (Shopware sends the same field in the address record).
 */
class DropshipAddressMapper
{
    /** Printess only accepts countryState for these countries. */
    private const STATE_COUNTRIES = ['US', 'CA'];

    /** Keys of the reproduction link (`sales_order.printess_reproduction_of`, PMD-21) that hold the original's note. */
    private const REPRODUCTION_NOTE_KEYS = ['customer_note', 'customerNote', 'customerComment'];

    /**
     * The dispatch notice of an order: its customer note, or, for a copy made to reproduce dropship items
     * (`printess_reproduction_of` JSON), the original order's note — the copy's own comment is the merchant's message.
     */
    public static function dispatchNotice(?string $customerNote, mixed $reproductionOf = null): string
    {
        if (is_string($reproductionOf) && trim($reproductionOf) !== '') {
            $decoded = json_decode($reproductionOf, true);
            if (is_array($decoded)) {
                foreach (self::REPRODUCTION_NOTE_KEYS as $key) {
                    if (array_key_exists($key, $decoded)) {
                        return is_scalar($decoded[$key]) ? trim((string) $decoded[$key]) : '';
                    }
                }
                return '';
            }
        }

        return trim((string) $customerNote);
    }

    public function map(array $address, string $email, string $dispatchNotice = ''): array
    {
        $lines   = $this->streetLines($address['street'] ?? []);
        $country = strtoupper($this->str($address['country_id'] ?? ''));

        // Always present: Printess needs a name, address1 and the country; city/zip are sent even when
        // blank so the record shape is predictable for the partner.
        $json = [
            'firstName' => $this->str($address['firstname'] ?? ''),
            'lastName'  => $this->str($address['lastname'] ?? ''),
            'address1'  => $lines[0] ?? '',
            'city'      => $this->str($address['city'] ?? ''),
            'zip'       => $this->str($address['postcode'] ?? ''),
            'country'   => $country,
        ];

        $optional = [
            'companyName' => $this->str($address['company'] ?? ''),
            'address2'    => $lines[1] ?? '',
            // Magento allows up to 4 street lines; anything past the 3rd is folded into address3.
            'address3'    => implode(', ', array_slice($lines, 2)),
            'countryState' => in_array($country, self::STATE_COUNTRIES, true)
                ? strtoupper($this->str($address['region_code'] ?? ''))
                : '',
            'phone'       => $this->str($address['telephone'] ?? ''),
            'email'       => trim($email),
            'dispatchNotice' => trim($dispatchNotice),
        ];

        foreach ($optional as $key => $value) {
            if ($value !== '') {
                $json[$key] = $value;
            }
        }

        return $json;
    }

    /**
     * @return string[] non-empty, trimmed lines
     */
    private function streetLines(mixed $street): array
    {
        if (is_string($street)) {
            $street = explode("\n", $street);
        }
        if (!is_array($street)) {
            return [];
        }

        $lines = [];
        foreach ($street as $line) {
            $line = $this->str($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        return $lines;
    }

    private function str(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
