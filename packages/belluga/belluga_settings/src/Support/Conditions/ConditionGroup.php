<?php

declare(strict_types=1);

namespace Belluga\Settings\Support\Conditions;

use InvalidArgumentException;

final class ConditionGroup
{
    /**
     * @param  array<int, ConditionRule>  $rules
     */
    public function __construct(public readonly array $rules) {}

    /**
     * @param  array<string, mixed>  $rawGroup
     * @param  array<string, array{id:string,type:string,nullable:bool}>  $fieldReferences
     */
    public static function fromArray(array $rawGroup, array $fieldReferences): self
    {
        $rawRules = $rawGroup['rules'] ?? null;
        if (! is_array($rawRules) || ! array_is_list($rawRules) || $rawRules === []) {
            throw new InvalidArgumentException('Condition group requires a non-empty rules list.');
        }

        $rules = [];
        foreach ($rawRules as $index => $rawRule) {
            if (! is_array($rawRule)) {
                throw new InvalidArgumentException("Condition group rule at index [{$index}] must be an object.");
            }

            $rules[] = ConditionRule::fromArray($rawRule, $fieldReferences);
        }

        return new self($rules);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rules' => array_map(
                static fn (ConditionRule $rule): array => $rule->toArray(),
                $this->rules
            ),
        ];
    }
}
