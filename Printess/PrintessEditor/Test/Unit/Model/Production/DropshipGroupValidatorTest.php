<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Test\Unit\Model\Production;

use PHPUnit\Framework\TestCase;
use Printess\PrintessEditor\Model\Production\DropshipGroupValidator;

class DropshipGroupValidatorTest extends TestCase
{
    private DropshipGroupValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new DropshipGroupValidator();
    }

    private static function job(int $jobId, int $itemId, array $overrides = []): array
    {
        return array_merge([
            'job_id'                => $jobId,
            'order_item_id'         => $itemId,
            'save_token'            => 'st:abc',
            'product_definition_id' => 17,
        ], $overrides);
    }

    public function testValidGroup(): void
    {
        self::assertSame([], $this->validator->validate([self::job(1, 10), self::job(2, 11)], [10, 11, 12], true));
    }

    public function testDefinitionZeroMeansTemplateAndIsValid(): void
    {
        self::assertSame([], $this->validator->validate(
            [self::job(1, 10, ['product_definition_id' => 0]), self::job(2, 11, ['product_definition_id' => '0'])],
            [10, 11],
            true
        ));
    }

    public function testEachProblemIsReported(): void
    {
        $problems = $this->validator->validate([
            self::job(1, 10, ['save_token' => '  ']),
            self::job(2, 11, ['product_definition_id' => null]),
            self::job(3, 99),
            self::job(4, 12, ['save_token' => null, 'product_definition_id' => '']),
        ], ['10', 11, 12], true);

        self::assertSame(
            [
                [1, 10, DropshipGroupValidator::CODE_NO_SAVE_TOKEN],
                [2, 11, DropshipGroupValidator::CODE_NO_DEFINITION],
                [3, 99, DropshipGroupValidator::CODE_ITEM_MISSING],
                [4, 12, DropshipGroupValidator::CODE_NO_SAVE_TOKEN],
                [4, 12, DropshipGroupValidator::CODE_NO_DEFINITION],
            ],
            array_map(static fn (array $p): array => [$p['job_id'], $p['order_item_id'], $p['code']], $problems)
        );
    }

    public function testMissingAddressFailsTheGroup(): void
    {
        $problems = $this->validator->validate([self::job(1, 10)], [10], false);
        self::assertCount(1, $problems);
        self::assertSame(DropshipGroupValidator::CODE_NO_ADDRESS, $problems[0]['code']);
    }

    public function testNegativeOrNonNumericDefinitionIsInvalid(): void
    {
        $problems = $this->validator->validate(
            [self::job(1, 10, ['product_definition_id' => -1]), self::job(2, 11, ['product_definition_id' => 'abc'])],
            [10, 11],
            true
        );
        self::assertSame(
            [DropshipGroupValidator::CODE_NO_DEFINITION, DropshipGroupValidator::CODE_NO_DEFINITION],
            array_column($problems, 'code')
        );
    }

    public function testSummarizeSingleCode(): void
    {
        $summary = DropshipGroupValidator::summarize(
            $this->validator->validate([self::job(1, 10, ['save_token' => ''])], [10], true)
        );
        self::assertSame(DropshipGroupValidator::CODE_NO_SAVE_TOKEN, $summary['code']);
        self::assertSame(
            'The dropship group was not sent (nothing reached Printess). item #10: no save token (design) is stored.',
            $summary['message']
        );
    }

    public function testSummarizeMixedCodes(): void
    {
        $summary = DropshipGroupValidator::summarize(
            $this->validator->validate([self::job(1, 10, ['save_token' => '']), self::job(2, 99)], [10], false)
        );
        self::assertSame(DropshipGroupValidator::CODE_GROUP_INVALID, $summary['code']);
        self::assertStringContainsString('neither a shipping nor a billing address', $summary['message']);
        self::assertStringContainsString('item #10: no save token', $summary['message']);
        self::assertStringContainsString('item #99: the order item no longer exists', $summary['message']);
    }

    public function testInactiveDesignFailsTheGroupUnknownDoesNot(): void
    {
        $validator = new DropshipGroupValidator();
        $jobs = [
            ['job_id' => 1, 'order_item_id' => 11, 'label' => 'P1'],
            ['job_id' => 2, 'order_item_id' => 12, 'label' => 'P2'],
            ['job_id' => 3, 'order_item_id' => 13],
        ];
        $problems = $validator->tokenProblems($jobs, [
            1 => \Printess\PrintessEditor\Model\SaveTokenStatus::ACTIVE,
            2 => \Printess\PrintessEditor\Model\SaveTokenStatus::INACTIVE,
            3 => \Printess\PrintessEditor\Model\SaveTokenStatus::UNKNOWN,
        ]);
        self::assertSame([[2, 12, DropshipGroupValidator::CODE_DESIGN_GONE]], array_map(
            static fn (array $p): array => [$p['job_id'], $p['order_item_id'], $p['code']],
            $problems
        ));

        $summary = DropshipGroupValidator::summarize($problems);
        self::assertSame(DropshipGroupValidator::CODE_DESIGN_GONE, $summary['code']);
        self::assertStringContainsString('item P2: the design (save token) does not exist at Printess', $summary['message']);
        self::assertStringStartsWith('The dropship group was not sent (nothing reached Printess).', $summary['message']);
    }

    public function testMessagesUseTheSkuWhenGiven(): void
    {
        $problems = (new DropshipGroupValidator())->validate(
            [['job_id' => 1, 'order_item_id' => 11, 'save_token' => '', 'product_definition_id' => 5, 'label' => 'P1'],
             ['job_id' => 2, 'order_item_id' => 12, 'save_token' => '', 'product_definition_id' => 5]],
            [11, 12],
            true
        );
        $message = DropshipGroupValidator::summarize($problems)['message'];
        self::assertStringContainsString('item P1: no save token', $message);
        self::assertStringContainsString('item #12: no save token', $message);
    }
}
