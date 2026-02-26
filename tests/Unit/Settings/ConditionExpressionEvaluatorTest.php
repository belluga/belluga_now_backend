<?php

declare(strict_types=1);

namespace Tests\Unit\Settings;

use Belluga\Settings\Support\Conditions\ConditionExpression;
use Belluga\Settings\Validation\ConditionExpressionEvaluator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConditionExpressionEvaluatorTest extends TestCase
{
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
        ], [
            'events.mode' => ['id' => 'events.mode', 'type' => 'string', 'nullable' => false],
            'events.stock_enabled' => ['id' => 'events.stock_enabled', 'type' => 'boolean', 'nullable' => false],
            'events.default_duration_hours' => ['id' => 'events.default_duration_hours', 'type' => 'integer', 'nullable' => false],
        ]);

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
}

