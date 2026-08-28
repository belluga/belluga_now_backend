<?php

declare(strict_types=1);

namespace Tests\Support;

use MongoDB\Driver\Monitoring\CommandFailedEvent;
use MongoDB\Driver\Monitoring\CommandStartedEvent;
use MongoDB\Driver\Monitoring\CommandSubscriber;
use MongoDB\Driver\Monitoring\CommandSucceededEvent;

final class MongoCommandTrace implements CommandSubscriber
{
    /** @var list<array{name:string,command:array<string,mixed>}> */
    private array $commands = [];

    public function commandStarted(CommandStartedEvent $event): void
    {
        $this->commands[] = [
            'name' => $event->getCommandName(),
            'command' => $this->arrayFrom($event->getCommand()),
        ];
    }

    public function commandSucceeded(CommandSucceededEvent $event): void {}

    public function commandFailed(CommandFailedEvent $event): void {}

    /** @return list<array<string,mixed>> */
    public function commandsForCollection(string $collection): array
    {
        return array_values(array_map(
            static fn (array $entry): array => $entry['command'],
            array_filter($this->commands, static function (array $entry) use ($collection): bool {
                $command = $entry['command'];

                return ($command[$entry['name']] ?? null) === $collection;
            }),
        ));
    }

    public function countForCollection(string $collection, string $commandName): int
    {
        return count(array_filter(
            $this->commands,
            static fn (array $entry): bool => $entry['name'] === $commandName
                && ($entry['command'][$commandName] ?? null) === $collection,
        ));
    }

    public function countCommand(string $commandName): int
    {
        return count(array_filter(
            $this->commands,
            static fn (array $entry): bool => $entry['name'] === $commandName,
        ));
    }

    /** @return array<string,int> collection-command keys in the tenant trace */
    public function collectionCommandMatrix(): array
    {
        $matrix = [];
        foreach ($this->commands as $entry) {
            if (! in_array($entry['name'], ['find', 'aggregate', 'count', 'update', 'delete', 'findAndModify'], true)) {
                continue;
            }
            $collection = $entry['command'][$entry['name']] ?? null;
            if (! is_string($collection) || $collection === '') {
                continue;
            }
            $key = $collection.':'.$entry['name'];
            $matrix[$key] = ($matrix[$key] ?? 0) + 1;
        }
        ksort($matrix);

        return $matrix;
    }

    /**
     * The driver represents an update command as an envelope containing one or
     * more operations.  Keeping that distinction observable prevents a broad
     * updateMany-style regression from passing an envelope-count assertion.
     *
     * @return list<array{filter:array<string,mixed>,update:mixed,array_filters:list<array<string,mixed>>,multi:bool,upsert:bool}>
     */
    public function updateOperationsForCollection(string $collection): array
    {
        $operations = [];
        foreach ($this->commands as $entry) {
            if ($entry['name'] !== 'update' || ($entry['command']['update'] ?? null) !== $collection) {
                continue;
            }

            foreach ($this->arrayFrom($entry['command']['updates'] ?? []) as $operation) {
                $operation = $this->arrayFrom($operation);
                $arrayFilters = $this->arrayFrom($operation['arrayFilters'] ?? []);
                $operations[] = [
                    'filter' => $this->arrayFrom($operation['q'] ?? []),
                    'update' => $operation['u'] ?? [],
                    'array_filters' => array_values(array_map(
                        fn (mixed $filter): array => $this->arrayFrom($filter),
                        $arrayFilters,
                    )),
                    'multi' => (bool) ($operation['multi'] ?? false),
                    'upsert' => (bool) ($operation['upsert'] ?? false),
                ];
            }
        }

        return $operations;
    }

    public function hasNonEmptyTenantIdOnSingleUpdate(string $collection): bool
    {
        $operations = $this->updateOperationsForCollection($collection);
        $tenantId = $operations[0]['filter']['tenant_id'] ?? null;

        return count($operations) === 1 && is_string($tenantId) && trim($tenantId) !== '';
    }

    /** @param array<string,mixed> $expected */
    public function hasExactlyOneSingleUpdateWith(
        string $collection,
        array $expected,
        callable $assertUpdate,
        array $expectedArrayFilters = [],
    ): bool {
        $operations = $this->updateOperationsForCollection($collection);
        if (count($operations) !== 1) {
            return false;
        }

        $operation = $operations[0];

        return $this->contains($operation['filter'], $expected)
            && $operation['multi'] === false
            && $operation['upsert'] === false
            && $operation['array_filters'] === $expectedArrayFilters
            && $assertUpdate($operation['update']);
    }

    public function hasFindForDocumentType(string $collection, string $documentType): bool
    {
        foreach ($this->commands as $entry) {
            if ($entry['name'] !== 'find' || ($entry['command']['find'] ?? null) !== $collection) {
                continue;
            }

            $filter = $this->arrayFrom($entry['command']['filter'] ?? []);
            if (($filter['doc_type'] ?? null) === $documentType) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $expected */
    public function hasFilterContaining(string $collection, string $commandName, array $expected): bool
    {
        foreach ($this->commands as $entry) {
            if ($entry['name'] !== $commandName || ($entry['command'][$commandName] ?? null) !== $collection) {
                continue;
            }

            $filters = match ($commandName) {
                'find' => [$this->arrayFrom($entry['command']['filter'] ?? [])],
                'aggregate' => [$this->arrayFrom($entry['command']['pipeline'][0]['$match'] ?? [])],
                'update' => array_map(
                    fn (mixed $update): array => $this->arrayFrom($this->arrayFrom($update)['q'] ?? []),
                    $this->arrayFrom($entry['command']['updates'] ?? []),
                ),
                default => [],
            };

            foreach ($filters as $filter) {
                if (array_diff_assoc($expected, $filter) === []) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string, mixed> $expected */
    public function hasExactlyOneNonUpsertSingleUpdateContaining(string $collection, array $expected): bool
    {
        foreach ($this->commands as $entry) {
            if ($entry['name'] !== 'update' || ($entry['command']['update'] ?? null) !== $collection) {
                continue;
            }

            $updates = $this->arrayFrom($entry['command']['updates'] ?? []);
            if (count($updates) !== 1) {
                continue;
            }
            $update = $this->arrayFrom($updates[0]);
            $filter = $this->arrayFrom($update['q'] ?? []);
            if (array_diff_assoc($expected, $filter) !== []) {
                continue;
            }
            if (($update['multi'] ?? false) !== false || ($update['upsert'] ?? false) !== false) {
                continue;
            }

            return true;
        }

        return false;
    }

    /** @return array<string,mixed> */
    private function arrayFrom(mixed $value): array
    {
        $items = is_object($value) ? get_object_vars($value) : $value;
        if (! is_array($items)) {
            return [];
        }

        return array_map(function (mixed $item): mixed {
            if (is_array($item) || is_object($item)) {
                if ($item instanceof \MongoDB\BSON\ObjectId || $item instanceof \MongoDB\BSON\UTCDateTime) {
                    return $this->scalarFrom($item);
                }

                return $this->arrayFrom($item);
            }

            return $this->scalarFrom($item);
        }, $items);
    }

    private function scalarFrom(mixed $value): mixed
    {
        if ($value instanceof \MongoDB\BSON\ObjectId) {
            return (string) $value;
        }

        if ($value instanceof \MongoDB\BSON\UTCDateTime) {
            return $value->toDateTime()->format('Uv');
        }

        return $value;
    }

    /** @param array<string,mixed> $actual @param array<string,mixed> $expected */
    private function contains(array $actual, array $expected): bool
    {
        foreach ($expected as $key => $expectedValue) {
            if (! array_key_exists($key, $actual)) {
                return false;
            }
            $actualValue = $actual[$key];
            if (is_array($expectedValue)) {
                if (! is_array($actualValue) || ! $this->contains($actualValue, $expectedValue)) {
                    return false;
                }

                continue;
            }
            if ($actualValue !== $expectedValue) {
                return false;
            }
        }

        return true;
    }
}
