<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Test\Unit\Model\Production;

use PHPUnit\Framework\TestCase;
use Printess\PrintessEditor\Model\Production\ProductionResultFiles;

final class ProductionResultFilesTest extends TestCase
{
    /** The result Printess returned for order 000000008 (print settings with JPG output). */
    public function testPictureOutputFromRealResult(): void
    {
        $result = json_decode('{"s":{"t":0},"r":[],"d":[],"p":[{"d":"Production","u":"https://output.printess.com/a/b/x_1_000.jpg","i":0}],"meta":null,"zip":null,"ff":"https://output.printess.com/a/b/x_formFields.json","ps":null}', true);

        $files = ProductionResultFiles::fromResult($result);

        self::assertSame([[
            'type' => 'image', 'document' => 'Production', 'index' => 0,
            'url' => 'https://output.printess.com/a/b/x_1_000.jpg', 'extension' => 'jpg',
        ]], $files);
        self::assertSame(['Production (JPG)'], ProductionResultFiles::labels($files));
    }

    public function testPdfObjectsAndPages(): void
    {
        $files = ProductionResultFiles::fromResult(['r' => [
            ['d' => 'Production', 'u' => 'https://o.printess.com/p1.pdf', 'i' => 0],
            ['d' => 'Production', 'u' => 'https://o.printess.com/p2.pdf', 'i' => 1],
            ['d' => 'Cover', 'u' => 'https://o.printess.com/c.pdf', 'i' => 0],
        ]]);

        self::assertCount(3, $files);
        self::assertSame('pdf', $files[0]['type']);
        self::assertSame(
            ['Production page 1 (PDF)', 'Production page 2 (PDF)', 'Cover (PDF)'],
            ProductionResultFiles::labels($files)
        );
    }

    public function testLegacyMapAndList(): void
    {
        $map = ProductionResultFiles::fromResult(['r' => ['document' => 'https://o.printess.com/x.pdf']]);
        self::assertSame('document', $map[0]['document']);
        self::assertSame('pdf', $map[0]['extension']);

        $list = ProductionResultFiles::fromResult(['r' => ['https://o.printess.com/a', 'https://o.printess.com/b']]);
        self::assertSame(['pdf', 'pdf'], array_column($list, 'extension'));
        self::assertSame([0, 1], array_column($list, 'index'));
    }

    public function testZipAndOrder(): void
    {
        $files = ProductionResultFiles::fromResult([
            'zip' => 'https://o.printess.com/all.zip',
            'p' => [['d' => 'P', 'u' => 'https://o.printess.com/1.png', 'i' => 0]],
            'r' => [['d' => 'R', 'u' => 'https://o.printess.com/1.pdf', 'i' => 0]],
        ]);
        self::assertSame(['pdf', 'image', 'zip'], array_column($files, 'type'));
        self::assertSame(['pdf', 'png', 'zip'], array_column($files, 'extension'));
        self::assertSame('Archive (ZIP)', ProductionResultFiles::labels($files)[2]);
    }

    public function testIgnoresNonFilesAndUnsafeUrls(): void
    {
        self::assertSame([], ProductionResultFiles::fromResult(null));
        self::assertSame([], ProductionResultFiles::fromResult('x'));
        self::assertSame([], ProductionResultFiles::fromResult([
            'r' => [], 'p' => [['d' => 'X', 'u' => 'javascript:alert(1)'], ['d' => 'Y'], 'ftp://x/y.pdf'],
            'zip' => null, 'ff' => 'https://o.printess.com/ff.json',
        ]));
    }

    public function testDuplicateUrlsAndLabelsAreUnique(): void
    {
        $files = ProductionResultFiles::fromResult(['r' => [
            ['d' => 'A', 'u' => 'https://o.printess.com/1.pdf', 'i' => 0],
            ['d' => 'A', 'u' => 'https://o.printess.com/1.pdf', 'i' => 0],
        ], 'p' => [
            ['d' => 'A', 'u' => 'https://o.printess.com/1.jpg', 'i' => 0],
            ['d' => 'A', 'u' => 'https://o.printess.com/2.jpg?sig=1', 'i' => 0],
        ]]);
        self::assertCount(3, $files);
        self::assertSame('jpg', $files[2]['extension']);
        $labels = ProductionResultFiles::labels($files);
        self::assertSame(count($labels), count(array_unique($labels)));
    }
}
