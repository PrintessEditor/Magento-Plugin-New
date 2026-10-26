<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Test\Unit\Model\Production;

use PHPUnit\Framework\TestCase;
use Printess\PrintessEditor\Model\Production\EnqueueFailureNote;

final class EnqueueFailureNoteTest extends TestCase
{
    public function testNoFailuresGivesEmptyNote(): void
    {
        self::assertSame('', EnqueueFailureNote::build([]));
    }

    public function testSingleItem(): void
    {
        $note = EnqueueFailureNote::build([['name' => 'P4', 'sku' => 'P4', 'error' => 'Unknown named parameter $config']]);

        self::assertStringStartsWith('Printess: production could not be queued for 1 item.', $note);
        self::assertStringContainsString('- "P4" (SKU P4): Unknown named parameter $config', $note);
        self::assertStringContainsString('var/log/system.log', $note);
    }

    public function testSeveralItemsAndFallbacks(): void
    {
        $note = EnqueueFailureNote::build([
            ['name' => 'Card', 'sku' => '', 'error' => "line one\n  line two"],
            ['name' => '  ', 'sku' => 'X1', 'error' => ''],
        ]);

        self::assertStringStartsWith('Printess: production could not be queued for 2 items.', $note);
        self::assertStringContainsString('- "Card": line one line two', $note);
        self::assertStringContainsString('- "Item" (SKU X1): unknown error', $note);
    }

    public function testLongErrorIsShortened(): void
    {
        $note = EnqueueFailureNote::build([['name' => 'A', 'sku' => 'A', 'error' => str_repeat('x', 1000)]]);
        $line = explode("\n", $note)[1];

        self::assertStringEndsWith('…', $line);
        self::assertLessThan(340, mb_strlen($line));
    }
}
