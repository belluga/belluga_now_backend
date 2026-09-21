<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles\Capabilities;

use App\Support\Auth\AbilityCatalog;
use InvalidArgumentException;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

final class AccountProfileCapabilityRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $definitions;

    /**
     * @param  iterable<AccountProfileCapabilityContract>  $capabilities
     * @param  array<int, string>|null  $knownAbilities
     */
    public function __construct(iterable $capabilities, ?array $knownAbilities = null)
    {
        $knownAbilities ??= AbilityCatalog::all();
        $definitions = [];

        foreach ($capabilities as $capability) {
            if (! $capability instanceof AccountProfileCapabilityContract) {
                throw new InvalidArgumentException('Every account profile capability must implement the canonical contract.');
            }

            $definition = $capability->definition();
            $this->validateDefinition($definition, $knownAbilities);
            $key = $definition['key'];
            if (array_key_exists($key, $definitions)) {
                throw new InvalidArgumentException("Duplicate account profile capability key [{$key}].");
            }

            $definitions[$key] = $definition;
        }

        $this->validateDependencies($definitions);
        ksort($definitions, SORT_STRING);
        $this->definitions = $definitions;
    }

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /** @return array<string, mixed> */
    public function definition(string $key): array
    {
        $normalized = trim($key);
        if (! array_key_exists($normalized, $this->definitions)) {
            throw new InvalidArgumentException("Unknown account profile capability key [{$normalized}].");
        }

        return $this->definitions[$normalized];
    }

    public function isValidValue(array $definition, mixed $value): bool
    {
        return match ($definition['value_type']) {
            'boolean' => is_bool($value),
            'enum' => is_string($value) && in_array($value, $definition['allowed_values'], true),
            default => false,
        };
    }

    public function isValidParameterValue(array $parameter, mixed $value): bool
    {
        if (($parameter['value_type'] ?? null) !== 'integer' || ! is_int($value)) {
            return false;
        }

        foreach ($parameter['validations'] as $validation) {
            if ($validation['rule'] === 'min' && $value < $validation['value']) {
                return false;
            }
            if ($validation['rule'] === 'max' && $value > $validation['value']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @param  array<string, mixed>  $current
     * @return array<string, array{value:mixed,parameters:array<string,int>}>
     */
    public function completeCreationConfiguration(array $incoming = []): array
    {
        return $this->buildConfiguration($incoming, [], useFailClosedFallback: false);
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @param  array<string, mixed>  $current
     * @return array<string, array{value:mixed,parameters:array<string,int>}>
     */
    public function mergeExistingConfiguration(array $incoming, array $current): array
    {
        return $this->buildConfiguration($incoming, $current, useFailClosedFallback: true);
    }

    /**
     * Explicit registry repair is the only existing-document path allowed to
     * restore declaration defaults instead of runtime fail-closed values.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $defaults
     * @return array<string, array{value:mixed,parameters:array<string,int>}>
     */
    public function repairExistingConfiguration(array $current, array $defaults): array
    {
        return $this->buildConfiguration($current, $defaults, useFailClosedFallback: false);
    }

    /**
     * @param  array<string, mixed>  $primary
     * @param  array<string, mixed>  $secondary
     * @return array<string, array{value:mixed,parameters:array<string,int>}>
     */
    private function buildConfiguration(array $primary, array $secondary, bool $useFailClosedFallback): array
    {
        $configuration = [];
        foreach ($this->definitions as $key => $definition) {
            $source = array_key_exists($key, $primary)
                ? $this->nativeDocument($primary[$key])
                : $this->nativeDocument($secondary[$key] ?? []);
            $fallbackValue = $useFailClosedFallback
                ? $definition['fail_closed_value']
                : $definition['default_value'];
            $value = $source['value'] ?? null;
            if (! $this->isValidValue($definition, $value)) {
                $value = $fallbackValue;
            }

            $sourceParameters = $this->nativeDocument($source['parameters'] ?? []);
            $parameters = [];
            foreach ($definition['parameters'] as $parameter) {
                $candidate = $sourceParameters[$parameter['key']] ?? null;
                $parameters[$parameter['key']] = $this->isValidParameterValue($parameter, $candidate)
                    ? $candidate
                    : ($useFailClosedFallback
                        ? $parameter['fail_closed_value']
                        : $parameter['default_value']);
            }

            $configuration[$key] = [
                'value' => $value,
                'parameters' => $parameters,
            ];
        }

        return $configuration;
    }

    /**
     * Preserve the declaration's document/list distinction when MongoDB stores
     * empty resources. PHP's empty array is otherwise encoded as a BSON list.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function definitionForPersistence(array $definition): array
    {
        $resources = [];
        foreach ($definition['resources'] as $key => $resource) {
            $resources[$key] = new BSONDocument([
                'operations' => $resource['operations'],
            ]);
        }

        $persisted = [
            ...$definition,
            'resources' => new BSONDocument($resources),
        ];
        if (isset($definition['dependencies'])) {
            $persisted['dependencies'] = new BSONArray(array_map(
                static fn (array $dependency): BSONDocument => new BSONDocument([
                    'capability_key' => $dependency['capability_key'],
                    'accepted_values' => new BSONArray($dependency['accepted_values']),
                ]),
                $definition['dependencies'],
            ));
        }

        return $persisted;
    }

    /**
     * Preserve the configured capabilities/envelopes/parameters as BSON
     * documents, including the empty keyed parameter map.
     *
     * @param  array<string, array{value:mixed,parameters:array<string,int>}>  $configuration
     */
    public function configurationForPersistence(array $configuration): BSONDocument
    {
        $stored = [];
        foreach ($configuration as $key => $envelope) {
            $stored[$key] = new BSONDocument([
                'value' => $envelope['value'],
                'parameters' => new BSONDocument($envelope['parameters']),
            ]);
        }

        return new BSONDocument($stored);
    }

    /** @return array<string, mixed> */
    public function validationRules(bool $requireCompleteConfiguration = false): array
    {
        $keys = array_keys($this->definitions);
        $rules = [
            'capabilities' => [$requireCompleteConfiguration ? 'required' : 'sometimes', 'array:'.implode(',', $keys)],
        ];

        foreach ($this->definitions as $key => $definition) {
            $presence = $requireCompleteConfiguration ? 'required' : 'sometimes';
            $rules["capabilities.{$key}"] = [$presence, 'array:value,parameters'];
            $valueRules = ["required_with:capabilities.{$key}"];
            $valueRules[] = $definition['value_type'] === 'boolean'
                ? 'boolean'
                : 'string';
            if ($definition['value_type'] === 'enum') {
                $valueRules[] = 'in:'.implode(',', $definition['allowed_values']);
            }
            $rules["capabilities.{$key}.value"] = $valueRules;

            $parameterKeys = array_column($definition['parameters'], 'key');
            $rules["capabilities.{$key}.parameters"] = [
                $definition['parameters'] === []
                    ? 'sometimes'
                    : "required_with:capabilities.{$key}",
                $parameterKeys === [] ? 'array:__none__' : 'array:'.implode(',', $parameterKeys),
            ];
            foreach ($definition['parameters'] as $parameter) {
                $parameterRules = ["required_with:capabilities.{$key}.parameters", 'integer'];
                foreach ($parameter['validations'] as $validation) {
                    $parameterRules[] = $validation['rule'].':'.$validation['value'];
                }
                $rules["capabilities.{$key}.parameters.{$parameter['key']}"] = $parameterRules;
            }
        }

        return $rules;
    }

    /** @param array<string, mixed> $definition @return array<string, int> */
    public function failClosedParameters(array $definition): array
    {
        $parameters = [];
        foreach ($definition['parameters'] as $parameter) {
            $parameters[$parameter['key']] = $parameter['fail_closed_value'];
        }

        return $parameters;
    }

    /** @param array<string, mixed> $definition @return array<string, int> */
    public function defaultParameters(array $definition): array
    {
        $parameters = [];
        foreach ($definition['parameters'] as $parameter) {
            $parameters[$parameter['key']] = $parameter['default_value'];
        }

        return $parameters;
    }

    /** @param array<string, mixed> $definition @param array<int, string> $knownAbilities */
    private function validateDefinition(array $definition, array $knownAbilities): void
    {
        $key = $definition['key'] ?? null;
        if (! is_string($key) || preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1) {
            throw new InvalidArgumentException('Capability key must be non-empty snake_case.');
        }

        $domain = $definition['domain'] ?? null;
        if (! is_string($domain) || AccountProfileCapabilityDomain::tryFrom($domain) === null) {
            throw new InvalidArgumentException("Capability [{$key}] must declare one canonical domain.");
        }

        $valueType = $definition['value_type'] ?? null;
        if (! in_array($valueType, ['boolean', 'enum'], true)) {
            throw new InvalidArgumentException("Capability [{$key}] has an unsupported value type.");
        }
        if ($valueType === 'boolean') {
            if (! is_bool($definition['default_value'] ?? null) || ($definition['fail_closed_value'] ?? null) !== false) {
                throw new InvalidArgumentException("Boolean capability [{$key}] must have Boolean defaults and fail closed to false.");
            }
            if (array_key_exists('allowed_values', $definition)) {
                throw new InvalidArgumentException("Boolean capability [{$key}] cannot declare enum values.");
            }
        } else {
            $allowed = $definition['allowed_values'] ?? null;
            if (! is_array($allowed) || $allowed === [] || count($allowed) !== count(array_unique($allowed, SORT_REGULAR))) {
                throw new InvalidArgumentException("Enum capability [{$key}] must declare distinct allowed values.");
            }
            if (! $this->isValidValue($definition, $definition['default_value'] ?? null)
                || ! $this->isValidValue($definition, $definition['fail_closed_value'] ?? null)) {
                throw new InvalidArgumentException("Enum capability [{$key}] defaults must belong to allowed values.");
            }
        }

        $parameters = $definition['parameters'] ?? null;
        $resources = $definition['resources'] ?? null;
        if (! is_array($parameters) || ! array_is_list($parameters) || ! is_array($resources) || array_is_list($resources) && $resources !== []) {
            throw new InvalidArgumentException("Capability [{$key}] parameters/resources must use the canonical native structures.");
        }

        $parameterKeys = [];
        foreach ($parameters as $parameter) {
            $parameterKey = $parameter['key'] ?? null;
            if (! is_string($parameterKey) || preg_match('/^[a-z][a-z0-9_]*$/', $parameterKey) !== 1 || isset($parameterKeys[$parameterKey])) {
                throw new InvalidArgumentException("Capability [{$key}] has an invalid or duplicate parameter key.");
            }
            $parameterKeys[$parameterKey] = true;
            if (($parameter['value_type'] ?? null) !== 'integer'
                || ! is_int($parameter['default_value'] ?? null)
                || ! is_int($parameter['fail_closed_value'] ?? null)
                || ! is_array($parameter['validations'] ?? null)
                || ! array_is_list($parameter['validations'])) {
                throw new InvalidArgumentException("Capability [{$key}] parameter [{$parameterKey}] is not a typed integer declaration.");
            }
            foreach ($parameter['validations'] as $validation) {
                if (! in_array($validation['rule'] ?? null, ['min', 'max'], true) || ! is_int($validation['value'] ?? null)) {
                    throw new InvalidArgumentException("Capability [{$key}] parameter [{$parameterKey}] has an unsupported validation.");
                }
            }
            if (! $this->isValidParameterValue($parameter, $parameter['default_value'])
                || ! $this->isValidParameterValue($parameter, $parameter['fail_closed_value'])) {
                throw new InvalidArgumentException("Capability [{$key}] parameter [{$parameterKey}] defaults violate validation.");
            }
        }

        foreach ($resources as $resourceKey => $resource) {
            if (! is_string($resourceKey) || preg_match('/^[a-z][a-z0-9_]*$/', $resourceKey) !== 1 || str_contains($resourceKey, '.')) {
                throw new InvalidArgumentException("Capability [{$key}] has an invalid resource key.");
            }
            $operations = $resource['operations'] ?? null;
            if (! is_array($operations) || ! array_is_list($operations)) {
                throw new InvalidArgumentException("Capability [{$key}] resource [{$resourceKey}] must declare an operations list.");
            }
            foreach ($operations as $operation) {
                $operationKey = $operation['key'] ?? null;
                $ability = $operation['ability'] ?? null;
                if (! is_string($operationKey) || preg_match('/^[a-z][a-z0-9_]*$/', $operationKey) !== 1
                    || ! is_string($ability) || ! in_array($ability, $knownAbilities, true)) {
                    throw new InvalidArgumentException("Capability [{$key}] resource [{$resourceKey}] declares an invalid operation or ability.");
                }
            }
        }
    }

    /** @param array<string, array<string, mixed>> $definitions */
    private function validateDependencies(array $definitions): void
    {
        foreach ($definitions as $key => $definition) {
            if (! array_key_exists('dependencies', $definition)) {
                continue;
            }
            $dependencies = $definition['dependencies'];
            if (! is_array($dependencies) || ! array_is_list($dependencies) || $dependencies === []) {
                throw new InvalidArgumentException("Capability [{$key}] dependencies must be omitted or a non-empty list.");
            }
            $seen = [];
            foreach ($dependencies as $dependency) {
                if (! is_array($dependency)
                    || array_keys($dependency) !== ['capability_key', 'accepted_values']
                    || ! is_string($dependency['capability_key'] ?? null)) {
                    throw new InvalidArgumentException("Capability [{$key}] has an invalid dependency declaration.");
                }
                $dependencyKey = $dependency['capability_key'];
                if ($dependencyKey === $key || isset($seen[$dependencyKey]) || ! isset($definitions[$dependencyKey])) {
                    throw new InvalidArgumentException("Capability [{$key}] has an invalid dependency target [{$dependencyKey}].");
                }
                $seen[$dependencyKey] = true;
                $accepted = $dependency['accepted_values'] ?? null;
                $target = $definitions[$dependencyKey];
                if (! is_array($accepted) || ! array_is_list($accepted) || $accepted === []
                    || count($accepted) !== count(array_unique($accepted, SORT_REGULAR))) {
                    throw new InvalidArgumentException("Capability [{$key}] dependency [{$dependencyKey}] must declare distinct accepted values.");
                }
                foreach ($accepted as $value) {
                    if (! $this->isValidValue($target, $value) || $value === $target['fail_closed_value']) {
                        throw new InvalidArgumentException("Capability [{$key}] dependency [{$dependencyKey}] contains an invalid or fail-closed value.");
                    }
                }
            }
        }

        $visiting = [];
        $visited = [];
        $visit = function (string $key) use (&$visit, &$visiting, &$visited, $definitions): void {
            if (isset($visiting[$key])) {
                throw new InvalidArgumentException("Capability dependency cycle detected at [{$key}].");
            }
            if (isset($visited[$key])) {
                return;
            }
            $visiting[$key] = true;
            foreach ($definitions[$key]['dependencies'] ?? [] as $dependency) {
                $visit($dependency['capability_key']);
            }
            unset($visiting[$key]);
            $visited[$key] = true;
        };
        foreach (array_keys($definitions) as $key) {
            $visit($key);
        }
    }

    /** @return array<string, mixed> */
    private function nativeDocument(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
            return $value->getArrayCopy();
        }
        if ($value instanceof \Traversable) {
            return iterator_to_array($value);
        }

        return [];
    }
}
