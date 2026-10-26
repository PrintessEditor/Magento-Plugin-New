<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Test\Unit\Model\Reorder;

use PHPUnit\Framework\TestCase;
use Printess\PrintessEditor\Model\Reorder\NewDesignPreset;

class NewDesignPresetTest extends TestCase
{
    private const CUSTOM = [
        ['title' => 'Paper', 'optionId' => '3', 'values' => [
            ['label' => 'normal', 'id' => '10'], ['label' => 'premium', 'id' => '11'],
        ]],
    ];
    private const VARIANT = [
        ['label' => 'Color', 'attributeId' => '93', 'options' => ['red' => '5', 'blue' => '6']],
    ];

    public function testPriorityPredefinedThenEditorThenSelections(): void
    {
        $fields = NewDesignPreset::formFields(
            [['fieldName' => 'Format', 'fieldValue' => 'A4'], ['fieldName' => 'Paper', 'fieldValue' => 'cheap']],
            [
                'printessFormFields' => json_encode(['Format' => 'A3', 'Paper' => 'normal', 'Name' => 'Anna']),
                'options' => ['3' => '11'],
                'super_attribute' => ['93' => '6'],
            ],
            self::CUSTOM,
            self::VARIANT
        );
        $byName = array_column($fields, 'value', 'name');
        self::assertSame(['Format' => 'A3', 'Paper' => 'premium', 'Name' => 'Anna', 'Color' => 'blue'], $byName);
    }

    public function testEditorFieldsAsArrayAndJunkIgnored(): void
    {
        $fields = NewDesignPreset::formFields(
            [['fieldName' => ' ', 'fieldValue' => 'x'], 'junk'],
            ['printessFormFields' => ['Name' => 'Ben', '' => 'x', 'List' => ['a']]],
            [],
            []
        );
        self::assertSame([['name' => 'Name', 'value' => 'Ben']], $fields);
    }

    public function testSelectionsOnlyForCurrentValues(): void
    {
        $sel = NewDesignPreset::selections(
            ['options' => ['3' => '99', '4' => '1'], 'super_attribute' => ['93' => '5', '94' => '7']],
            self::CUSTOM,
            self::VARIANT
        );
        self::assertSame(['super_attribute' => ['93' => '5'], 'options' => []], $sel);
    }

    public function testEmptyBuyRequest(): void
    {
        self::assertSame([], NewDesignPreset::formFields([], [], self::CUSTOM, self::VARIANT));
        self::assertSame(
            ['super_attribute' => [], 'options' => []],
            NewDesignPreset::selections([], self::CUSTOM, self::VARIANT)
        );
    }
}
