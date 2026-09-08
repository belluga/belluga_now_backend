<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Support\Validation\InputConstraints;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AccountProfileNestedGroupService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function normalizeMetadataForWrite(mixed $rawGroups): array
    {
        if (! is_array($rawGroups)) {
            return [];
        }

        if (count($rawGroups) > InputConstraints::ACCOUNT_PROFILE_NESTED_GROUPS_MAX) {
            throw ValidationException::withMessages([
                'nested_profile_groups' => ['Nested profile groups exceed the configured limit.'],
            ]);
        }

        $groups = [];
        $groupIds = [];

        foreach ($rawGroups as $index => $rawGroup) {
            if (! is_array($rawGroup)) {
                throw ValidationException::withMessages([
                    "nested_profile_groups.{$index}" => ['Nested profile group must be an object.'],
                ]);
            }

            $label = trim((string) ($rawGroup['label'] ?? ''));
            if ($label === '') {
                throw ValidationException::withMessages([
                    "nested_profile_groups.{$index}.label" => ['Nested profile group label is required.'],
                ]);
            }

            $id = $this->normalizeGroupId($rawGroup['id'] ?? $rawGroup['key'] ?? null, $label, $index);
            if (isset($groupIds[$id])) {
                throw ValidationException::withMessages([
                    "nested_profile_groups.{$index}.id" => ['Nested profile group ids must be unique.'],
                ]);
            }
            $groupIds[$id] = true;

            $groups[] = [
                '_source_index' => $index,
                'id' => $id,
                'label' => $label,
                'order' => isset($rawGroup['order']) ? (int) $rawGroup['order'] : $index,
                'member_count' => max(0, (int) ($rawGroup['member_count'] ?? 0)),
            ];
        }

        usort(
            $groups,
            static fn (array $left, array $right): int => [$left['order'], $left['_source_index']]
                <=> [$right['order'], $right['_source_index']]
        );

        return array_values(array_map(
            static fn (array $group): array => [
                'id' => $group['id'],
                'label' => $group['label'],
                'order' => $group['order'],
                'member_count' => $group['member_count'],
            ],
            $groups
        ));
    }

    public function assertMetadataOnlyInput(mixed $rawGroups): void
    {
        if (! is_array($rawGroups)) {
            return;
        }

        $errors = [];
        foreach ($rawGroups as $index => $rawGroup) {
            if (! is_array($rawGroup)) {
                continue;
            }

            if (array_key_exists('account_profile_ids', $rawGroup)) {
                $errors["nested_profile_groups.{$index}.account_profile_ids"] = [
                    'Nested profile group members must be managed through the dedicated members endpoint.',
                ];
            }

            if (array_key_exists('profile_ids', $rawGroup)) {
                $errors["nested_profile_groups.{$index}.profile_ids"] = [
                    'Nested profile group members must be managed through the dedicated members endpoint.',
                ];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function formatMetadataForRead(mixed $rawGroups): array
    {
        if (! is_array($rawGroups)) {
            return [];
        }

        $groups = [];
        foreach ($rawGroups as $index => $rawGroup) {
            if (! is_array($rawGroup)) {
                continue;
            }

            $label = trim((string) ($rawGroup['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $id = $this->normalizeGroupId($rawGroup['id'] ?? $rawGroup['key'] ?? null, $label, $index);
            $groups[] = [
                '_source_index' => $index,
                'id' => $id,
                'label' => $label,
                'order' => isset($rawGroup['order']) ? (int) $rawGroup['order'] : $index,
                'member_count' => max(0, (int) ($rawGroup['member_count'] ?? 0)),
            ];
        }

        usort(
            $groups,
            static fn (array $left, array $right): int => [$left['order'], $left['_source_index']]
                <=> [$right['order'], $right['_source_index']]
        );

        return array_values(array_map(
            static fn (array $group): array => [
                'id' => $group['id'],
                'label' => $group['label'],
                'order' => $group['order'],
                'member_count' => $group['member_count'],
            ],
            $groups
        ));
    }

    private function normalizeGroupId(mixed $rawId, string $label, int $index): string
    {
        $id = trim((string) ($rawId ?? ''));
        if ($id === '') {
            $id = Str::slug($label);
        }
        if ($id === '') {
            $id = 'group-'.$index;
        }
        $id = Str::lower($id);

        if (
            strlen($id) > InputConstraints::ACCOUNT_PROFILE_NESTED_GROUP_KEY_MAX
            || ! preg_match('/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/', $id)
        ) {
            throw ValidationException::withMessages([
                'nested_profile_groups' => ['Nested profile group id is invalid.'],
            ]);
        }

        return $id;
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<string, mixed>
     */
    public function findGroupOrFail(array $groups, string $groupId): array
    {
        $normalizedGroupId = Str::lower(trim($groupId));
        foreach ($groups as $group) {
            if (trim((string) ($group['id'] ?? '')) === $normalizedGroupId) {
                return $group;
            }
        }

        throw new NotFoundHttpException;
    }
}
