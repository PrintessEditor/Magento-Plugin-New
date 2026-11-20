<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Test\Unit\Model\CustomerFields;

use PHPUnit\Framework\TestCase;
use Printess\PrintessEditor\Model\CustomerFields\UserDataSource as S;

final class UserDataSourceTest extends TestCase
{
    public function testNormalizeMapDropsUnknownAndEmptyAndKeepsLastPerField(): void
    {
        $map = S::normalizeMap(json_encode([
            ['fieldName' => ' firstName ', 'source' => 'first_name'],
            ['fieldName' => 'x', 'source' => 'password'],
            ['fieldName' => '', 'source' => 'email'],
            ['fieldName' => 'y', 'source' => ''],
            ['fieldName' => 'z', 'source' => 'email', 'delete' => true],
            ['fieldName' => 'firstName', 'source' => 'full_name'],
            'garbage',
        ]));
        self::assertSame([['fieldName' => 'firstName', 'source' => 'full_name']], $map);
        self::assertSame([], S::normalizeMap('not json'));
        self::assertSame([], S::normalizeMap(null));
    }

    public function testValuesWithBillingAddress(): void
    {
        $v = S::values(
            ['firstname' => ' Sam ', 'lastname' => 'Submitter', 'email' => 'sam@example.com'],
            [
                'company' => 'ACME GmbH', 'street' => ['Hauptstr. 1', '', 'Hinterhaus'], 'postcode' => '20095',
                'city' => 'Hamburg', 'region' => 'Hamburg', 'country_id' => 'de', 'country_name' => 'Deutschland',
                'telephone' => "+49 40 123\t",
            ]
        );
        self::assertSame('Sam', $v[S::FIRST_NAME]);
        self::assertSame('Sam Submitter', $v[S::FULL_NAME]);
        self::assertSame('+49 40 123', $v[S::PHONE]);
        self::assertSame('Hauptstr. 1, Hinterhaus', $v[S::STREET]);
        self::assertSame('Hinterhaus', $v[S::STREET_2]);
        self::assertSame('DE', $v[S::COUNTRY_CODE]);
        self::assertSame('Deutschland', $v[S::COUNTRY]);
        self::assertSame("ACME GmbH\nHauptstr. 1\nHinterhaus\n20095 Hamburg\nDeutschland", $v[S::ADDRESS_BLOCK]);
        self::assertSame(array_keys(S::LABELS), array_keys($v), 'every source has a value');
    }

    public function testValuesWithoutAddressAndResolveSkipsEmpty(): void
    {
        $v = S::values(['firstname' => 'Sam', 'lastname' => '', 'email' => 'sam@example.com'], null);
        self::assertSame('', $v[S::PHONE]);
        self::assertSame('', $v[S::ADDRESS_BLOCK]);
        self::assertSame('Sam', $v[S::FULL_NAME]);

        $fields = S::resolve([
            ['fieldName' => 'name', 'source' => S::FULL_NAME],
            ['fieldName' => 'tel', 'source' => S::PHONE],
            ['fieldName' => 'mail', 'source' => S::EMAIL],
        ], $v);
        self::assertSame([['name' => 'name', 'value' => 'Sam'], ['name' => 'mail', 'value' => 'sam@example.com']], $fields);
    }
}
