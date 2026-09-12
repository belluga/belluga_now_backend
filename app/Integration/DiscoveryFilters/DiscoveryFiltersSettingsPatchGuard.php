<?php

declare(strict_types=1);

namespace App\Integration\DiscoveryFilters;

use Belluga\DiscoveryFilters\Registry\DiscoveryFilterEntityRegistry;
use Belluga\Settings\Contracts\SettingsNamespacePatchGuardContract;
use Belluga\Settings\Support\BsonNormalizer;
use Belluga\Settings\Support\SettingsNamespaceDefinition;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

final class DiscoveryFiltersSettingsPatchGuard implements SettingsNamespacePatchGuardContract
{
    public function __construct(
        private readonly TenantDiscoveryFilterSettingsAdapter $settings,
        private readonly DiscoveryFilterEntityRegistry $entities,
    ) {}

    /** @param array<string, mixed> $payload */
    public function guard(
        string $scope,
        mixed $user,
        string $namespace,
        array $payload,
        SettingsNamespaceDefinition $definition,
    ): void {
        if ($scope !== 'tenant' || $namespace !== 'discovery_filters') {
            return;
        }

        $effective = $this->settings->resolveDiscoveryFiltersSettings();
        foreach ($payload as $rawPath => $value) {
            if (! is_string($rawPath)) {
                continue;
            }

            $path = trim($rawPath);
            $prefix = $namespace.'.';
            if (str_starts_with($path, $prefix)) {
                $path = substr($path, strlen($prefix));
            }
            if ($path === '' || $path === $namespace) {
                continue;
            }

            Arr::set($effective, $path, BsonNormalizer::toArray($value));
        }

        $surfaces = BsonNormalizer::toArray($effective['surfaces'] ?? []);
        $surface = BsonNormalizer::toArray($surfaces['public_map.primary'] ?? []);
        $filters = BsonNormalizer::toArray($surface['filters'] ?? []);
        $errors = [];

        foreach (array_values($filters) as $index => $filter) {
            foreach ($this->publicMapFilterErrors($filter) as $field => $messages) {
                $errors["surfaces.public_map.primary.filters.{$index}.{$field}"] = $messages;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function isValidPublicMapFilter(mixed $filter): bool
    {
        return $this->publicMapFilterErrors($filter) === [];
    }

    /** @return array<string, array<int, string>> */
    private function publicMapFilterErrors(mixed $filter): array
    {
        $filter = BsonNormalizer::toArray($filter);
        $query = BsonNormalizer::toArray($filter['query'] ?? []);
        $entities = BsonNormalizer::toArray($query['entities'] ?? []);
        $normalizedEntities = [];
        foreach ($entities as $entity) {
            if (is_string($entity) && trim($entity) !== '') {
                $normalizedEntities[] = strtolower(trim($entity));
            }
        }

        $errors = [];
        if (count($normalizedEntities) !== 1) {
            $errors['query.entities'] = ['Public Map filters must select exactly one entity.'];

            return $errors;
        }

        $selectedEntity = $normalizedEntities[0];
        if ($this->entities->provider($selectedEntity) === null) {
            $errors['query.entities'] = ['Public Map filter entity is not registered.'];
        }

        $typesByEntity = BsonNormalizer::toArray($query['types_by_entity'] ?? []);
        foreach (array_keys($typesByEntity) as $entity) {
            if (! is_string($entity) || strtolower(trim($entity)) !== $selectedEntity) {
                $errors['query.types_by_entity'] = [
                    'Public Map filter types must belong to the selected entity.',
                ];
                break;
            }
        }

        return $errors;
    }
}
