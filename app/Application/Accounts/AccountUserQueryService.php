<?php

declare(strict_types=1);

namespace App\Application\Accounts;

use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class AccountUserQueryService
{
    /**
     * @param array<string, mixed> $filters
     */
    public function paginate(
        Account $account,
        array $filters,
        bool $includeArchived,
        int $perPage = 15
    ): LengthAwarePaginator {
        $query = AccountUser::query()
            ->where('account_roles.account_id', $account->id)
            ->when($includeArchived, static fn (Builder $builder): Builder => $builder->onlyTrashed());

        $this->applyFilters($query, $filters);

        return $query
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $filters = $this->sanitizeFilters($filters);

        foreach ($filters as $field => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $this->applyFilter($query, $field, $value);
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function sanitizeFilters(array $filters): array
    {
        $raw = $filters['filter'] ?? $filters;

        if (! is_array($raw)) {
            return [];
        }

        $raw = collect($raw)
            ->only($this->searchableFields())
            ->toArray();

        // Merge top-level filters that match a searchable field (e.g., ?name=foo)
        $topLevel = Arr::only($filters, $this->searchableFields());

        return array_merge($raw, $topLevel);
    }

    /**
     * @param mixed $value
     */
    private function applyFilter(Builder $query, string $field, $value): void
    {
        if (in_array($field, $this->stringFields(), true)) {
            $this->applyStringFilter($query, $field, (string) $value);

            return;
        }

        if (in_array($field, $this->arrayFields(), true)) {
            $this->applyArrayFilter($query, $field, $value);

            return;
        }

        if (in_array($field, $this->dateFields(), true)) {
            $this->applyDateFilter($query, $field, $value);

            return;
        }

        if ($field === 'version') {
            $query->where($field, (int) $value);

            return;
        }

        $query->where($field, $value);
    }

    private function applyStringFilter(Builder $query, string $field, string $value): void
    {
        $pattern = $this->makeLikePattern($value);
        $query->where($field, 'like', $pattern);
    }

    /**
     * @param mixed $value
     */
    private function applyArrayFilter(Builder $query, string $field, $value): void
    {
        $values = is_array($value) ? $value : [$value];

        $normalized = collect($values)
            ->filter(static fn ($value): bool => $value !== null && $value !== '')
            ->map(static fn ($value): string => (string) $value)
            ->values()
            ->all();

        if ($normalized === []) {
            return;
        }

        $query->where($field, 'all', $normalized);
    }

    /**
     * @param mixed $value
     */
    private function applyDateFilter(Builder $query, string $field, $value): void
    {
        if (is_array($value)) {
            if (isset($value['from'])) {
                $from = $this->parseDate($value['from']);
                if ($from) {
                    $query->whereDate($field, '>=', $from->toDateString());
                }
            }

            if (isset($value['to'])) {
                $to = $this->parseDate($value['to']);
                if ($to) {
                    $query->whereDate($field, '<=', $to->toDateString());
                }
            }

            return;
        }

        $parsed = $this->parseDate($value);
        if ($parsed) {
            $query->whereDate($field, $parsed->toDateString());
        }
    }

    private function parseDate($value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function makeLikePattern(string $value): string
    {
        return '%' . addcslashes($value, '%_\\') . '%';
    }

    /**
     * @return array<int, string>
     */
    private function stringFields(): array
    {
        return ['name', 'identity_state'];
    }

    /**
     * @return array<int, string>
     */
    private function arrayFields(): array
    {
        return ['emails', 'phones'];
    }

    /**
     * @return array<int, string>
     */
    private function dateFields(): array
    {
        return ['first_seen_at', 'registered_at'];
    }

    /**
     * @return array<int, string>
     */
    private function searchableFields(): array
    {
        static $fields;

        if ($fields === null) {
            $model = new AccountUser();
            $excluded = [
                'password',
                'credentials',
                'consents',
                'promotion_audit',
                'merged_source_ids',
                'fingerprints',
            ];

            $fields = array_values(array_diff($model->getFillable(), $excluded));
        }

        return $fields;
    }
}
