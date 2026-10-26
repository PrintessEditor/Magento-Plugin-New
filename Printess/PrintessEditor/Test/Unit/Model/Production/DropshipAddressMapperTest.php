<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Test\Unit\Model\Production;

use PHPUnit\Framework\TestCase;
use Printess\PrintessEditor\Model\Production\DropshipAddressMapper;

class DropshipAddressMapperTest extends TestCase
{
    private DropshipAddressMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new DropshipAddressMapper();
    }

    private static function address(array $overrides = []): array
    {
        return array_merge([
            'firstname'   => 'Jane',
            'lastname'    => 'Doe',
            'company'     => 'ACME',
            'street'      => ['Main St 1'],
            'city'        => 'Berlin',
            'postcode'    => '10115',
            'country_id'  => 'DE',
            'region_code' => 'BE',
            'telephone'   => '+49 30 123',
        ], $overrides);
    }

    public function testFullMapping(): void
    {
        self::assertSame([
            'firstName'   => 'Jane',
            'lastName'    => 'Doe',
            'address1'    => 'Main St 1',
            'city'        => 'Berlin',
            'zip'         => '10115',
            'country'     => 'DE',
            'companyName' => 'ACME',
            'phone'       => '+49 30 123',
            'email'       => 'jane@example.com',
        ], $this->mapper->map(self::address(), 'jane@example.com'));
    }

    public function testCountryStateOnlyForUsAndCa(): void
    {
        $us = $this->mapper->map(self::address(['country_id' => 'US', 'region_code' => 'ny']), 'x@y.z');
        self::assertSame('NY', $us['countryState']);

        $ca = $this->mapper->map(self::address(['country_id' => 'ca', 'region_code' => 'ON']), 'x@y.z');
        self::assertSame('CA', $ca['country']);
        self::assertSame('ON', $ca['countryState']);

        $de = $this->mapper->map(self::address(['country_id' => 'DE', 'region_code' => 'BE']), 'x@y.z');
        self::assertArrayNotHasKey('countryState', $de);

        $usNoRegion = $this->mapper->map(self::address(['country_id' => 'US', 'region_code' => '']), 'x@y.z');
        self::assertArrayNotHasKey('countryState', $usNoRegion);
    }

    public function testAddress2And3(): void
    {
        $one = $this->mapper->map(self::address(['street' => ['Line 1']]), '');
        self::assertArrayNotHasKey('address2', $one);
        self::assertArrayNotHasKey('address3', $one);

        $two = $this->mapper->map(self::address(['street' => ['Line 1', 'Line 2']]), '');
        self::assertSame('Line 2', $two['address2']);
        self::assertArrayNotHasKey('address3', $two, 'address3 only when there is a 3rd street line');

        $three = $this->mapper->map(self::address(['street' => ['Line 1', 'Line 2', 'Line 3']]), '');
        self::assertSame('Line 3', $three['address3']);

        $four = $this->mapper->map(self::address(['street' => ['Line 1', 'Line 2', 'Line 3', 'Line 4']]), '');
        self::assertSame('Line 3, Line 4', $four['address3']);
    }

    public function testBlankStreetLinesAreSkipped(): void
    {
        $result = $this->mapper->map(self::address(['street' => ['  Line 1 ', '', '   ', ' Line 2']]), '');
        self::assertSame('Line 1', $result['address1']);
        self::assertSame('Line 2', $result['address2']);
        self::assertArrayNotHasKey('address3', $result);
    }

    public function testStreetAsNewlineSeparatedString(): void
    {
        $result = $this->mapper->map(self::address(['street' => "Line 1\nLine 2\nLine 3"]), '');
        self::assertSame('Line 1', $result['address1']);
        self::assertSame('Line 2', $result['address2']);
        self::assertSame('Line 3', $result['address3']);
    }

    public function testTrimming(): void
    {
        $result = $this->mapper->map([
            'firstname'   => '  Jane ',
            'lastname'    => "\tDoe\n",
            'company'     => '   ',
            'street'      => ['  Main St 1  '],
            'city'        => ' Berlin ',
            'postcode'    => ' 10115 ',
            'country_id'  => ' us ',
            'region_code' => ' ca ',
            'telephone'   => '   ',
        ], '  jane@example.com  ');

        self::assertSame([
            'firstName'    => 'Jane',
            'lastName'     => 'Doe',
            'address1'     => 'Main St 1',
            'city'         => 'Berlin',
            'zip'          => '10115',
            'country'      => 'US',
            'countryState' => 'CA',
            'email'        => 'jane@example.com',
        ], $result);
    }

    public function testEmptyOptionalFieldsOmittedAndRequiredKeysAlwaysPresent(): void
    {
        $result = $this->mapper->map([], '');
        self::assertSame(
            ['firstName' => '', 'lastName' => '', 'address1' => '', 'city' => '', 'zip' => '', 'country' => ''],
            $result
        );
    }

    public function testNonScalarValuesBecomeEmpty(): void
    {
        $result = $this->mapper->map(self::address(['city' => ['x'], 'company' => null, 'street' => 42]), '');
        self::assertSame('', $result['city']);
        self::assertSame('', $result['address1']);
        self::assertArrayNotHasKey('companyName', $result);
    }
    public function testDispatchNoticeIsSentWhenPresent(): void
    {
        $result = $this->mapper->map(self::address(), 'jane@example.com', "  Please ring twice \n");
        self::assertSame('Please ring twice', $result['dispatchNotice']);

        self::assertArrayNotHasKey('dispatchNotice', $this->mapper->map(self::address(), 'x@y.z', '   '));
        self::assertArrayNotHasKey('dispatchNotice', $this->mapper->map(self::address(), 'x@y.z'));
    }

    public function testDispatchNoticeFromCustomerNote(): void
    {
        self::assertSame('Leave at the door', DropshipAddressMapper::dispatchNotice(' Leave at the door '));
        self::assertSame('', DropshipAddressMapper::dispatchNotice(null));
        self::assertSame('Note', DropshipAddressMapper::dispatchNotice('Note', ''));
        self::assertSame('Note', DropshipAddressMapper::dispatchNotice('Note', 'not json'));
    }

    public function testDispatchNoticeOfAReproductionCopyIsTheOriginalsNote(): void
    {
        $link = json_encode(['id' => 5, 'increment_id' => '000000005', 'customer_note' => 'Original note']);
        self::assertSame('Original note', DropshipAddressMapper::dispatchNotice('Merchant message', $link));

        $camel = json_encode(['id' => 5, 'customerNote' => ' Camel ']);
        self::assertSame('Camel', DropshipAddressMapper::dispatchNotice('Merchant message', $camel));

        // A copy whose original had no note must not send the merchant's message as dispatch notice.
        $noNote = json_encode(['id' => 5, 'increment_id' => '000000005']);
        self::assertSame('', DropshipAddressMapper::dispatchNotice('Merchant message', $noNote));
    }
}
