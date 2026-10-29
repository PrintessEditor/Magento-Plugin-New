<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Test\Unit\Model\Production;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Printess\PrintessEditor\Model\Production\DropshipMode;
use Printess\PrintessEditor\Model\Production\DropshipResolution;

/**
 * Dropshipping is a product-level setting only (no store default). Precedence child -> parent; the first
 * non-empty level decides; legacy 'use_default' counts as empty.
 * Results: null = no dropship, 0 = template, >0 = explicit definition.
 */
class DropshipResolutionTest extends TestCase
{
    private DropshipResolution $resolution;

    protected function setUp(): void
    {
        $this->resolution = new DropshipResolution();
    }

    private static function level(?string $mode, ?int $definitionId = null): array
    {
        return ['mode' => $mode, 'definition_id' => $definitionId];
    }

    public function testChildWinsOverParent(): void
    {
        $levels = [self::level(DropshipMode::DROPSHIPPING, 11), self::level(DropshipMode::DROPSHIPPING, 22)];
        self::assertSame(11, $this->resolution->resolve($levels));
    }

    public function testSingleProductModes(): void
    {
        self::assertSame(11, $this->resolution->resolve([self::level(DropshipMode::DROPSHIPPING, 11)]));
        self::assertSame(0, $this->resolution->resolve([self::level(DropshipMode::TEMPLATE, 11)]));
        self::assertNull($this->resolution->resolve([self::level(DropshipMode::NONE, 11)]));
    }

    /**
     * @dataProvider emptyModes
     */
    #[DataProvider('emptyModes')]
    public function testAllLevelsEmptyMeansNoDropship(?string $mode): void
    {
        self::assertNull($this->resolution->resolve([self::level($mode, 11), self::level($mode, 22)]));
        self::assertNull($this->resolution->resolve([self::level($mode, 11)]));
    }

    public function testNoLevelsMeansNoDropship(): void
    {
        self::assertNull($this->resolution->resolve([]));
    }

    /**
     * @dataProvider emptyModes
     */
    #[DataProvider('emptyModes')]
    public function testEmptyChildInheritsExplicitParent(?string $mode): void
    {
        self::assertSame(22, $this->resolution->resolve([self::level($mode, 11), self::level(DropshipMode::DROPSHIPPING, 22)]));
        self::assertSame(0, $this->resolution->resolve([self::level($mode, 11), self::level(DropshipMode::TEMPLATE)]));
        self::assertNull($this->resolution->resolve([self::level($mode, 11), self::level(DropshipMode::NONE)]));
    }

    public static function emptyModes(): array
    {
        return [
            'null'                => [null],
            'empty'               => [''],
            'whitespace'          => ['  '],
            'unknown'             => ['bogus'],
            'legacy use_default'  => [DropshipMode::USE_DEFAULT],
        ];
    }

    public function testMissingModeKeyMeansNotSet(): void
    {
        self::assertNull($this->resolution->resolve([[]]));
        self::assertSame(22, $this->resolution->resolve([[], self::level(DropshipMode::DROPSHIPPING, 22)]));
    }

    /**
     * @dataProvider explicitDropshipWithoutId
     */
    #[DataProvider('explicitDropshipWithoutId')]
    public function testExplicitDropshippingWithoutUsableIdIsNullWithoutFallthrough(?int $definitionId): void
    {
        $levels = [self::level(DropshipMode::DROPSHIPPING, $definitionId), self::level(DropshipMode::DROPSHIPPING, 22)];
        self::assertNull(
            $this->resolution->resolve($levels),
            'An explicit dropshipping level without a usable id must not fall through to the parent.'
        );
    }

    public static function explicitDropshipWithoutId(): array
    {
        return ['null' => [null], 'zero' => [0], 'minus one' => [-1]];
    }

    public function testExplicitChildNoneOverridesParent(): void
    {
        self::assertNull($this->resolution->resolve([self::level(DropshipMode::NONE), self::level(DropshipMode::DROPSHIPPING, 22)]));
        self::assertNull($this->resolution->resolve([self::level(DropshipMode::NONE), self::level(DropshipMode::TEMPLATE)]));
    }

    public function testModeIsTrimmed(): void
    {
        self::assertSame(0, $this->resolution->resolve([self::level(' template ')]));
        self::assertSame(5, $this->resolution->resolve([self::level(' dropshipping ', 5)]));
    }

    public function testDropshipModeConstants(): void
    {
        self::assertSame('none', DropshipMode::NONE);
        self::assertSame('dropshipping', DropshipMode::DROPSHIPPING);
        self::assertSame('template', DropshipMode::TEMPLATE);
        self::assertSame('use_default', DropshipMode::USE_DEFAULT);
    }
}
