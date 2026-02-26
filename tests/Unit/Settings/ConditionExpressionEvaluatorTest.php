<?php

declare(strict_types=1);

namespace Tests\Unit\Settings;

use Belluga\Settings\Support\Conditions\ConditionExpression;
use Belluga\Settings\Validation\ConditionExpressionEvaluator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConditionExpressionEvaluatorTest extends TestCase
{
    /**
     * @return array<string, array{id:string,type:string,nullable:bool}>
     */
    private function references(): array
    {
        return [
            'events.mode' => ['id' => 'events.mode', 'type' => 'string', 'nullable' => false],
            'events.stock_enabled' => ['id' => 'events.stock_enabled', 'type' => 'boolean', 'nullable' => false],
            'events.default_duration_hours' => ['id' => 'events.default_duration_hours', 'type' => 'integer', 'nullable' => false],
            'events.priority' => ['id' => 'events.priority', 'type' => 'number', 'nullable' => false],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     */
    private function expressionWithRules(array $rules): ConditionExpression
    {
        return ConditionExpression::fromArray([
            'groups' => [
                ['rules' => $rules],
            ],
        ], $this->references());
    }

    #[Test]
    public function it_evaluates_or_of_and_expression(): void
    {
        $expression = ConditionExpression::fromArray([
            'groups' => [
                [
                    'rules' => [
                        ['field_id' => 'events.mode', 'operator' => 'equals', 'value' => 'advanced'],
                        ['field_id' => 'events.stock_enabled', 'operator' => 'equals', 'value' => true],
                    ],
                ],
                [
                    'rules' => [
                        ['field_id' => 'events.default_duration_hours', 'operator' => 'gte', 'value' => 8],
                    ],
                ],
            ],
        ], $this->references());

        $evaluator = new ConditionExpressionEvaluator();

        $this->assertTrue($evaluator->evaluate($expression, [
            'events' => [
                'mode' => 'advanced',
                'stock_enabled' => true,
                'default_duration_hours' => 3,
            ],
        ]));

        $this->assertTrue($evaluator->evaluate($expression, [
            'events' => [
                'mode' => 'basic',
                'stock_enabled' => false,
                'default_duration_hours' => 9,
            ],
        ]));

        $this->assertFalse($evaluator->evaluate($expression, [
            'events' => [
                'mode' => 'basic',
                'stock_enabled' => false,
                'default_duration_hours' => 3,
            ],
        ]));
    }

    #[Test]
    public function it_evaluates_equals_and_not_equals(): void
    {
        $evaluator = new ConditionExpressionEvaluator();
        $expression = $this->expressionWithRules([
            ['field_id' => 'events.mode', 'operator' => 'equals', 'value' => 'advanced'],
            ['field_id' => 'events.stock_enabled', 'operator' => 'not_equals', 'value' => false],
        ]);

        $this->assertTrue($evaluator->evaluate($expression, [
            'events' => ['mode' => 'advanced', 'stock_enabled' => true],
        ]));

        $this->assertFalse($evaluator->evaluate($expression, [
            'events' => ['mode' => 'basic', 'stock_enabled' => true],
        ]));
    }

    #[Test]
    public function it_evaluates_in_and_not_in(): void
    {
        $evaluator = new ConditionExpressionEvaluator();
        $expression = $this->expressionWithRules([
            ['field_id' => 'events.mode', 'operator' => 'in', 'value' => ['advanced', 'pro']],
            ['field_id' => 'events.mode', 'operator' => 'not_in', 'value' => ['legacy']],
        ]);

        $this->assertTrue($evaluator->evaluate($expression, [
            'events' => ['mode' => 'advanced'],
        ]));

        $this->assertFalse($evaluator->evaluate($expression, [
            'events' => ['mode' => 'legacy'],
        ]));
    }

    #[Test]
    public function it_evaluates_numeric_comparators(): void
    {
        $evaluator = new ConditionExpressionEvaluator();
        $expression = $this->expressionWithRules([
            ['field_id' => 'events.default_duration_hours', 'operator' => 'gt', 'value' => 3],
            ['field_id' => 'events.default_duration_hours', 'operator' => 'gte', 'value' => 4],
            ['field_id' => 'events.default_duration_hours', 'operator' => 'lt', 'value' => 10],
            ['field_id' => 'events.default_duration_hours', 'operator' => 'lte', 'value' => 4],
        ]);

        $this->assertTrue($evaluator->evaluate($expression, [
            'events' => ['default_duration_hours' => 4],
        ]));

        $this->assertFalse($evaluator->evaluate($expression, [
            'events' => ['default_duration_hours' => 3],
        ]));
    }

    #[Test]
    public function it_evaluates_exists_operator(): void
    {
        $evaluator = new ConditionExpressionEvaluator();
        $existsExpression = $this->expressionWithRules([
            ['field_id' => 'events.priority', 'operator' => 'exists', 'value' => true],
        ]);
        $missingExpression = $this->expressionWithRules([
            ['field_id' => 'events.priority', 'operator' => 'exists', 'value' => false],
        ]);

        $this->assertTrue($evaluator->evaluate($existsExpression, [
            'events' => ['priority' => 2.5],
        ]));
        $this->assertFalse($evaluator->evaluate($existsExpression, [
            'events' => [],
        ]));

        $this->assertTrue($evaluator->evaluate($missingExpression, [
            'events' => [],
        ]));
        $this->assertFalse($evaluator->evaluate($missingExpression, [
            'events' => ['priority' => 1.0],
        ]));
    }
}
