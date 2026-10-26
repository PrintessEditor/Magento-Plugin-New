<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Test\Unit\Model\Options;

use PHPUnit\Framework\TestCase;
use Printess\PrintessEditor\Model\Options\InternalOptionFilter;

final class InternalOptionFilterTest extends TestCase
{
    public function testKeyedPrintessEntriesAreRemoved(): void
    {
        $options = [
            'printess_save_token'    => ['label' => 'Printess Save Token', 'value' => 'st:x'],
            'printess_thumbnail_url' => ['label' => 'Printess Thumbnail', 'value' => 'https://…'],
            0                        => ['label' => 'Color', 'value' => 'red'],
        ];
        self::assertSame([0 => ['label' => 'Color', 'value' => 'red']], InternalOptionFilter::strip($options));
    }

    public function testListIsMatchedByLabelAndStaysAList(): void
    {
        $options = [
            ['label' => 'Color', 'value' => 'red'],
            ['label' => 'Printess Save Token', 'value' => 'st:x'],
            ['label' => ' Printess Thumbnail ', 'value' => 'https://…'],
            ['label' => 'Size', 'value' => 'L'],
        ];
        self::assertSame(
            [['label' => 'Color', 'value' => 'red'], ['label' => 'Size', 'value' => 'L']],
            InternalOptionFilter::strip($options)
        );
    }

    public function testOtherOptionsAndEmptyInputAreUntouched(): void
    {
        self::assertSame([], InternalOptionFilter::strip([]));
        $options = ['option_1' => ['label' => 'Engraving', 'value' => 'Hi'], 'printessish' => ['label' => 'x', 'value' => 'y']];
        self::assertSame($options, InternalOptionFilter::strip($options));
    }
}
