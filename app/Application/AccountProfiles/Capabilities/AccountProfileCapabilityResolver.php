<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles\Capabilities;

use App\Application\AccountProfiles\AccountProfileTypeIndexManifest;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use InvalidArgumentException;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use RuntimeException;

final class AccountProfileCapabilityResolver implements AccountProfileCapabilityResolverContract
{
    public function __construct(
        private readonly AccountProfileCapabilityRegistry $registry,
        private readonly AccountProfileCapabilityOverrideProviderContract $overrides,
        private readonly AccountProfileTypeIndexManifest $indexManifest,
    ) {}

    public function definitions(): array
    {
        return $this->registry->definitions();
    }

    public function definition(string $key): array
    {
        return $this->registry->definition($key);
    }

    public function materializeConfigurationForCreation(array $explicitValues = []): array
    {
        return $this->registry->completeCreationConfiguration($explicitValues);
    }

    public function mergeConfigurationForUpdate(array $incoming, array $current): array
    {
        return $this->registry->mergeExistingConfiguration($incoming, $current);
    }

    public function repairConfiguration(array $current, array $defaults): array
    {
        return $this->registry->repairExistingConfiguration($current, $defaults);
    }

    public function validationRules(bool $requireCompleteConfiguration = false): array
    {
        return $this->registry->validationRules($requireCompleteConfiguration);
    }

    public function configurationForPersistence(array $configuration): BSONDocument
    {
        return $this->registry->configurationForPersistence($configuration);
    }

    public function definitionForPersistence(array $definition): array
    {
        return $this->registry->definitionForPersistence($definition);
    }

    public function resolveForProfileType(TenantProfileType $profileType, string $key): array
    {
        $memo = [];
        $stack = [];

        return $this->resolve($profileType, $key, $memo, $stack);
    }

    public function resolveAllForProfileType(TenantProfileType $profileType): array
    {
        $memo = [];
        $stack = [];
        foreach (array_keys($this->registry->definitions()) as $key) {
            $this->resolve($profileType, $key, $memo, $stack);
        }

        return $memo;
    }

    /** @param array<string, array<string, mixed>> $memo @param array<string, true> $stack */
    private function resolve(TenantProfileType $profileType, string $key, array &$memo, array &$stack): array
    {
        if (isset($memo[$key])) {
            return $memo[$key];
        }
        if (isset($stack[$key])) {
            throw new RuntimeException("Capability dependency cycle reached at [{$key}].");
        }
        $stack[$key] = true;
        $definition = $this->registry->definition($key);
        $capabilities = $this->toArray($profileType->capabilities ?? []);
        $configuration = $this->toArray($capabilities[$key] ?? []);
        $value = $this->registry->isValidValue($definition, $configuration['value'] ?? null)
            ? $configuration['value']
            : $definition['fail_closed_value'];
        $parameters = $this->resolveBaselineParameters($definition, $configuration);

        $contribution = $this->overrides->contributionForProfileType($profileType, $key);
        if ($contribution !== null) {
            $overridden = $this->applyContribution($definition, $value, $parameters, $contribution);
            if ($overridden !== null) {
                [$value, $parameters] = $overridden;
            }
        }

        $configured = ['value' => $value, 'parameters' => $parameters];
        $effective = $configured;
        foreach ($definition['dependencies'] ?? [] as $dependency) {
            $dependencyResult = $this->resolve($profileType, $dependency['capability_key'], $memo, $stack);
            if (! in_array($dependencyResult['effective']['value'], $dependency['accepted_values'], true)) {
                $effective = [
                    'value' => $definition['fail_closed_value'],
                    'parameters' => $this->registry->failClosedParameters($definition),
                ];
                break;
            }
        }
        unset($stack[$key]);

        return $memo[$key] = array_replace($definition, [
            'configured' => $configured,
            'effective' => $effective,
        ]);
    }

    public function resolveForProfile(AccountProfile $profile, string $key): array
    {
        $profileType = $profile->relationLoaded('profileType')
            ? $profile->getRelation('profileType')
            : null;
        if (! $profileType instanceof TenantProfileType) {
            $profileType = TenantProfileType::query()
                ->where('type', trim((string) $profile->profile_type))
                ->first();
        }
        if (! $profileType instanceof TenantProfileType) {
            throw new RuntimeException('Account Profile capability resolution requires an owning Profile Type.');
        }

        return $this->resolveForProfileType($profileType, $key);
    }

    public function typeIdsWhereAllEffectiveValues(array $criteria): array
    {
        $expanded = $this->expandCriteria($criteria);
        $paths = array_map(static fn (string $key): string => "capabilities.{$key}.value", array_keys($expanded));
        $manifest = null;
        foreach ($this->indexManifest->definitions() as $candidate) {
            $candidatePaths = array_values(array_filter(
                array_keys($candidate['keys']),
                static fn (string $path): bool => $path !== 'type',
            ));
            if (count($candidatePaths) === count($paths)
                && array_diff($candidatePaths, $paths) === []
                && array_diff($paths, $candidatePaths) === []) {
                $manifest = $candidate;
                break;
            }
        }
        if ($manifest === null) {
            throw new InvalidArgumentException('Capability criteria shape is not backed by the canonical index manifest.');
        }

        $filter = ['capabilities' => ['$type' => 'object', '$not' => ['$type' => 'array']]];
        foreach (array_keys($manifest['keys']) as $path) {
            if ($path === 'type') {
                continue;
            }
            $key = explode('.', $path)[1];
            $definition = $this->registry->definition($key);
            $values = $expanded[$key];
            $filter["capabilities.{$key}"] = ['$type' => 'object', '$not' => ['$type' => 'array']];
            $filter[$path] = [
                count($values) === 1 ? '$eq' : '$in' => count($values) === 1 ? $values[0] : $values,
                '$type' => $definition['value_type'] === 'boolean' ? 'bool' : 'string',
                '$not' => ['$type' => 'array'],
            ];
        }

        return TenantProfileType::raw(static function ($collection) use ($filter, $manifest): array {
            $types = [];
            foreach ($collection->find($filter, [
                'projection' => ['_id' => 0, 'type' => 1],
                'sort' => ['type' => 1],
                'hint' => $manifest['name'],
            ]) as $document) {
                $type = trim((string) ($document['type'] ?? ''));
                if ($type !== '') {
                    $types[] = $type;
                }
            }

            return array_values(array_unique($types));
        });
    }

    /** @param array<string, scalar|array<int, scalar>> $criteria @return array<string, array<int, scalar>> */
    private function expandCriteria(array $criteria): array
    {
        if ($criteria === []) {
            throw new InvalidArgumentException('At least one capability criterion is required.');
        }
        $expanded = [];
        $add = function (string $key, mixed $expected) use (&$add, &$expanded): void {
            $definition = $this->registry->definition($key);
            $values = is_array($expected) ? array_values($expected) : [$expected];
            if ($values === [] || count($values) !== count(array_unique($values, SORT_REGULAR))) {
                throw new InvalidArgumentException("Capability criterion [{$key}] must contain distinct values.");
            }
            foreach ($values as $value) {
                if (! $this->registry->isValidValue($definition, $value) || $value === $definition['fail_closed_value']) {
                    throw new InvalidArgumentException("Capability criterion [{$key}] contains an invalid or fail-closed value.");
                }
            }
            if (isset($expanded[$key]) && $expanded[$key] !== $values) {
                throw new InvalidArgumentException("Capability criterion [{$key}] conflicts with its declared dependency.");
            }
            $expanded[$key] = $values;
            foreach ($definition['dependencies'] ?? [] as $dependency) {
                $add($dependency['capability_key'], $dependency['accepted_values']);
            }
        };
        foreach ($criteria as $key => $expected) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Capability criteria must be keyed by capability key.');
            }
            $add($key, $expected);
        }

        return $expanded;
    }

    /** @return array<string, int> */
    private function resolveBaselineParameters(array $definition, array $configuration): array
    {
        $configuredParameters = $this->toArray($configuration['parameters'] ?? []);
        $resolved = [];
        foreach ($definition['parameters'] as $parameter) {
            $candidate = $configuredParameters[$parameter['key']] ?? null;
            $resolved[$parameter['key']] = $this->registry->isValidParameterValue($parameter, $candidate)
                ? $candidate
                : $parameter['fail_closed_value'];
        }

        return $resolved;
    }

    /** @return array{0:mixed,1:array<string,int>}|null */
    private function applyContribution(array $definition, mixed $baselineValue, array $baselineParameters, array $contribution): ?array
    {
        $value = $baselineValue;
        if (array_key_exists('value', $contribution)) {
            if ($definition['value_type'] !== 'boolean' || $contribution['value'] !== true) {
                return null;
            }
            $value = true;
        }

        $parameters = $baselineParameters;
        if (array_key_exists('parameters', $contribution)) {
            $incoming = $this->toArray($contribution['parameters']);
            if (count($incoming) !== count((array) $contribution['parameters'])) {
                return null;
            }
            $definitionsByKey = [];
            foreach ($definition['parameters'] as $parameter) {
                $definitionsByKey[$parameter['key']] = $parameter;
            }
            foreach ($incoming as $parameterKey => $parameterValue) {
                if (! is_string($parameterKey)
                    || ! isset($definitionsByKey[$parameterKey])
                    || ! $this->registry->isValidParameterValue($definitionsByKey[$parameterKey], $parameterValue)) {
                    return null;
                }
                $parameters[$parameterKey] = $parameterValue;
            }
        }

        $unknownKeys = array_diff(array_keys($contribution), ['value', 'parameters']);
        if ($unknownKeys !== []) {
            return null;
        }

        return [$value, $parameters];
    }

    /** @return array<string, mixed> */
    private function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            return $value->getArrayCopy();
        }
        if ($value instanceof \Traversable) {
            return iterator_to_array($value);
        }

        return [];
    }
}
