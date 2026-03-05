<?php

declare(strict_types=1);

namespace Belluga\Settings\Support\Conditions;

use InvalidArgumentException;

final class ConditionRule
{
    public function __construct(
        public readonly string $fieldId,
        public readonly ConditionOperator $operator,
        public readonly mixed $value,
    ) {
    }

    /**
     * @param array<string, mixed> $rawRule
     * @param array<string, array{id:string,type:string,nullable:bool}> $fieldReferences
     */
    public static function fromArray(array $rawRule, array $fieldReferences): self
    {
        $rawFieldId = $rawRule['field_id'] ?? null;
        if (! is_string($rawFieldId) || trim($rawFieldId) === '') {
            throw new InvalidArgumentException('Condition rule requires a non-empty field_id.');
        }

        if (! array_key_exists($rawFieldId, $fieldReferences)) {
            throw new InvalidArgumentException("Condition rule references unknown field_id [{$rawFieldId}].");
        }

        $reference = $fieldReferences[$rawFieldId];
        $operatorRaw = $rawRule['operator'] ?? null;
        if (! is_string($operatorRaw) || trim($operatorRaw) === '') {
            throw new InvalidArgumentException("Condition rule [{$rawFieldId}] requires a non-empty operator.");
        }

        $operator = ConditionOperator::tryFrom($operatorRaw);
        if (! $operator) {
            throw new InvalidArgumentException("Condition rule [{$rawFieldId}] has unsupported operator [{$operatorRaw}].");
        }

        $value = $rawRule['value'] ?? null;

        if ($operator->expectsArrayValue()) {
            if (! is_array($value) || ! array_is_list($value)) {
                throw new InvalidArgumentException("Condition rule [{$rawFieldId}] with operator [{$operator->value}] requires an array value.");
            }

            foreach ($value as $index => $entry) {
                if (! self::isTypeCompatible((string) $reference['type'], (bool) $reference['nullable'], $entry)) {
                    throw new InvalidArgumentException("Condition rule [{$rawFieldId}] has invalid value type at index [{$index}].");
                }
            }
        } elseif ($operator === ConditionOperator::EXISTS) {
            if ($value === null) {
                $value = true;
            }

            if (! is_bool($value)) {
                throw new InvalidArgumentException("Condition rule [{$rawFieldId}] with operator [exists] requires a boolean value.");
            }
        } else {
            if (! self::isTypeCompatible((string) $reference['type'], (bool) $reference['nullable'], $value)) {
                throw new InvalidArgumentException("Condition rule [{$rawFieldId}] has invalid value type for operator [{$operator->value}].");
            }

            if ($operator->expectsComparableValue()) {
                $type = (string) $reference['type'];
                if (! in_array($type, ['integer', 'number', 'date', 'datetime'], true)) {
                    throw new InvalidArgumentException("Condition rule [{$rawFieldId}] uses comparable operator [{$operator->value}] on non-comparable type [{$type}].");
                }
            }
        }

        return new self((string) $reference['id'], $operator, $value);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'field_id' => $this->fieldId,
            'operator' => $this->operator->value,
            'value' => $this->value,
        ];
    }

    private static function isTypeCompatible(string $type, bool $nullable, mixed $value): bool
    {
        if ($value === null) {
            return $nullable;
        }

        return match ($type) {
            'boolean' => is_bool($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'string', 'date', 'datetime' => is_string($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && ! array_is_list($value),
            'mixed' => true,
            default => false,
        };
    }
}

