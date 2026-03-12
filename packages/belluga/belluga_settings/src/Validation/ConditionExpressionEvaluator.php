<?php

declare(strict_types=1);

namespace Belluga\Settings\Validation;

use Belluga\Settings\Support\Conditions\ConditionExpression;
use Belluga\Settings\Support\Conditions\ConditionOperator;
use Belluga\Settings\Support\Conditions\ConditionRule;

final class ConditionExpressionEvaluator
{
    /**
     * @param  array<string, mixed>  $state
     */
    public function evaluate(ConditionExpression $expression, array $state): bool
    {
        foreach ($expression->groups as $group) {
            $groupResult = true;

            foreach ($group->rules as $rule) {
                if (! $this->evaluateRule($rule, $state)) {
                    $groupResult = false;
                    break;
                }
            }

            if ($groupResult) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function evaluateRule(ConditionRule $rule, array $state): bool
    {
        [$exists, $actual] = $this->resolveFieldValue($state, $rule->fieldId);

        if ($rule->operator === ConditionOperator::EXISTS) {
            return $exists === (bool) $rule->value;
        }

        if (! $exists) {
            return false;
        }

        return match ($rule->operator) {
            ConditionOperator::EQUALS => $actual === $rule->value,
            ConditionOperator::NOT_EQUALS => $actual !== $rule->value,
            ConditionOperator::IN => is_array($rule->value) && in_array($actual, $rule->value, true),
            ConditionOperator::NOT_IN => is_array($rule->value) && ! in_array($actual, $rule->value, true),
            ConditionOperator::GT => $actual > $rule->value,
            ConditionOperator::GTE => $actual >= $rule->value,
            ConditionOperator::LT => $actual < $rule->value,
            ConditionOperator::LTE => $actual <= $rule->value,
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{0: bool, 1: mixed}
     */
    private function resolveFieldValue(array $state, string $fieldId): array
    {
        if (array_key_exists($fieldId, $state)) {
            return [true, $state[$fieldId]];
        }

        $sentinel = new \stdClass;
        $value = data_get($state, $fieldId, $sentinel);

        if ($value === $sentinel) {
            return [false, null];
        }

        return [true, $value];
    }
}
