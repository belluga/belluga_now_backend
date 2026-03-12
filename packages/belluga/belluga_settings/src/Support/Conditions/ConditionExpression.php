<?php

declare(strict_types=1);

namespace Belluga\Settings\Support\Conditions;

use InvalidArgumentException;

final class ConditionExpression
{
    public const MAX_GROUPS_PER_EXPRESSION = 10;

    public const MAX_RULES_PER_GROUP = 10;

    public const MAX_TOTAL_RULES_PER_EXPRESSION = 50;

    public const MAX_CONDITION_PAYLOAD_BYTES = 16384;

    /**
     * @param  array<int, ConditionGroup>  $groups
     */
    public function __construct(public readonly array $groups) {}

    /**
     * @param  array<string, mixed>  $rawExpression
     * @param  array<string, array{id:string,type:string,nullable:bool}>  $fieldReferences
     */
    public static function fromArray(array $rawExpression, array $fieldReferences): self
    {
        $payloadBytes = strlen((string) json_encode($rawExpression));
        if ($payloadBytes > self::MAX_CONDITION_PAYLOAD_BYTES) {
            throw new InvalidArgumentException('Condition expression payload exceeds 16KB limit.');
        }

        $rawGroups = $rawExpression['groups'] ?? null;
        if (! is_array($rawGroups) || ! array_is_list($rawGroups) || $rawGroups === []) {
            throw new InvalidArgumentException('Condition expression requires a non-empty groups list.');
        }

        if (count($rawGroups) > self::MAX_GROUPS_PER_EXPRESSION) {
            throw new InvalidArgumentException('Condition expression exceeds max group count.');
        }

        $groups = [];
        $totalRules = 0;

        foreach ($rawGroups as $index => $rawGroup) {
            if (! is_array($rawGroup)) {
                throw new InvalidArgumentException("Condition expression group at index [{$index}] must be an object.");
            }

            $group = ConditionGroup::fromArray($rawGroup, $fieldReferences);
            if (count($group->rules) > self::MAX_RULES_PER_GROUP) {
                throw new InvalidArgumentException("Condition expression group [{$index}] exceeds max rule count.");
            }

            $totalRules += count($group->rules);
            if ($totalRules > self::MAX_TOTAL_RULES_PER_EXPRESSION) {
                throw new InvalidArgumentException('Condition expression exceeds max total rule count.');
            }

            $groups[] = $group;
        }

        return new self($groups);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'groups' => array_map(
                static fn (ConditionGroup $group): array => $group->toArray(),
                $this->groups
            ),
        ];
    }
}
