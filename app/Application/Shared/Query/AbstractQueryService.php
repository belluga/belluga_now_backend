<?php

declare(strict_types=1);

namespace App\Application\Shared\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

abstract class AbstractQueryService
{
    protected function buildPaginator(
        Builder $query,
        array $queryParams,
        bool $includeArchived,
        int $perPage
    ): LengthAwarePaginator {
        if ($includeArchived) {
            $this->applyArchivedConstraint($query);
        }

        $extracted = $this->extractFiltersAndSort($queryParams);

        $this->applyFilters($query, $extracted['filters']);
        $sorted = $this->applySort($query, $extracted['sort']);

        if (! $sorted) {
            $default = $this->defaultSort();
            if ($default !== null) {
                $query->orderBy($default['field'], $default['direction']);
            }
        }

        return $query->paginate($perPage);
    }

    protected function applyArchivedConstraint(Builder $query): void
    {
        $query->onlyTrashed();
    }

    protected function defaultSort(): ?array
    {
        return [
            'field' => 'created_at',
            'direction' => 'desc',
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function sanitizeFilters(array $filters): array
    {
        $filterPayload = Arr::except($filters, ['sort']);
        $raw = $filterPayload['filter'] ?? $filterPayload;

        if (! is_array($raw)) {
            return [];
        }

        $raw = collect($raw)
            ->only($this->searchableFields())
            ->toArray();

        $topLevel = Arr::only($filterPayload, $this->searchableFields());

        return array_merge($raw, $topLevel);
    }

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

    private function applyFilter(Builder $query, string $field, mixed $value): void
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

        if (is_array($value)) {
            $query->whereIn($field, $value);
        } else {
            $query->where($field, $value);
        }
    }

    private function applyStringFilter(Builder $query, string $field, string $value): void
    {
        $pattern = '%' . addcslashes($value, '%_\\') . '%';
        $query->where($field, 'like', $pattern);
    }

    private function applyArrayFilter(Builder $query, string $field, mixed $value): void
    {
        $values = is_array($value) ? $value : [$value];

        $normalized = collect($values)
            ->filter(static fn ($item): bool => $item !== null && $item !== '')
            ->map(static fn ($item): string => (string) $item)
            ->values()
            ->all();

        if ($normalized === []) {
            return;
        }

        $query->where($field, 'all', $normalized);
    }

    private function applyDateFilter(Builder $query, string $field, mixed $value): void
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

    private function parseDate(mixed $value): ?Carbon
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

    /**
     * @param mixed $sortParam
     * @return array{field: string|null, direction: string}
     */
    private function resolveSort(mixed $sortParam): array
    {
        $field = null;
        $direction = 'asc';

        if (is_string($sortParam) && $sortParam !== '') {
            if (str_starts_with($sortParam, '-')) {
                $field = substr($sortParam, 1);
                $direction = 'desc';
            } else {
                $field = $sortParam;
            }
        } elseif (is_array($sortParam)) {
            $field = isset($sortParam['field']) ? (string) $sortParam['field'] : null;
            $dir = strtolower((string) ($sortParam['direction'] ?? 'asc'));
            if (in_array($dir, ['asc', 'desc'], true)) {
                $direction = $dir;
            }
        }

        if ($field === null || ! in_array($field, $this->sortableFields(), true)) {
            return ['field' => null, 'direction' => 'asc'];
        }

        return ['field' => $field, 'direction' => $direction];
    }

    private function applySort(Builder $query, array $sort): bool
    {
        $field = $sort['field'] ?? null;

        if ($field === null) {
            return false;
        }

        $direction = strtolower($sort['direction'] ?? 'asc');
        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : 'asc';

        $query->orderBy($field, $direction);

        return true;
    }

    /**
     * @return array{filters: array<string, mixed>, sort: array{field: string|null, direction: string}}
     */
    private function extractFiltersAndSort(array $queryParams): array
    {
        $sort = $this->resolveSort($queryParams['sort'] ?? null);

        return [
            'filters' => Arr::except($queryParams, ['sort']),
            'sort' => $sort,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function sortableFields(): array
    {
        return array_unique(array_merge($this->stringFields(), $this->dateFields()));
    }

    /**
     * @return array<int, string>
     */
    private function searchableFields(): array
    {
        static $cache = [];
        $class = static::class;

        if (! array_key_exists($class, $cache)) {
            $cache[$class] = array_unique(array_merge(
                $this->baseSearchableFields(),
                $this->stringFields(),
                $this->dateFields(),
                $this->arrayFields(),
                $this->extraSearchableFields()
            ));
        }

        return $cache[$class];
    }

    /**
     * @return array<int, string>
     */
    abstract protected function baseSearchableFields(): array;

    /**
     * @return array<int, string>
     */
    abstract protected function stringFields(): array;

    /**
     * @return array<int, string>
     */
    abstract protected function arrayFields(): array;

    /**
     * @return array<int, string>
     */
    abstract protected function dateFields(): array;

    /**
     * @return array<int, string>
     */
    protected function extraSearchableFields(): array
    {
        return [];
    }
}
