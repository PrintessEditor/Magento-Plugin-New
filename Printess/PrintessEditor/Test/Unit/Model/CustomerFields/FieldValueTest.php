<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Test\Unit\Model\CustomerFields;

use PHPUnit\Framework\TestCase;
use Printess\PrintessEditor\Model\CustomerFields\FieldValue;

final class FieldValueTest extends TestCase
{
    public function testNormalize(): void
    {
        self::assertSame('K-12345', FieldValue::normalize("  K-12345\n"));
        self::assertSame('AB', FieldValue::normalize("A\tB"));
        self::assertSame('', FieldValue::normalize(null));
        self::assertSame('Müller & Söhne', FieldValue::normalize('Müller & Söhne'));
        self::assertSame(str_repeat('x', 255), FieldValue::normalize(str_repeat('x', 255)));
        $this->expectException(\InvalidArgumentException::class);
        FieldValue::normalize(str_repeat('x', 256));
    }

    public function testMergeCustomerValueWins(): void
    {
        $fields = [['name' => 'SIZE', 'value' => 'A4'], ['name' => 'RM_CUST', 'value' => 'product-default']];
        self::assertSame(
            [['name' => 'SIZE', 'value' => 'A4'], ['name' => 'RM_CUST', 'value' => 'K-1']],
            FieldValue::merge($fields, ['RM_CUST' => 'K-1'])
        );
        self::assertSame(
            [['name' => 'SIZE', 'value' => 'A4'], ['name' => 'RM_CUST', 'value' => 'K-9']],
            FieldValue::merge([['name' => 'SIZE', 'value' => 'A4']], ['RM_CUST' => 'K-9'])
        );
        // no value for this store: product fields unchanged
        self::assertSame($fields, FieldValue::merge($fields, ['RM_CUST' => '']));
    }
}
