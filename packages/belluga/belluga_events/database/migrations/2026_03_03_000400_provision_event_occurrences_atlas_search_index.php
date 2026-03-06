<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MongoDB\Database;
use MongoDB\Driver\Exception\Exception as MongoDriverException;

return new class extends Migration
{
    private const COLLECTION = 'event_occurrences';
    private const INDEX_NAME = 'events_occurrences_agenda_search_v1';

    public function up(): void
    {
        $database = DB::connection('tenant')->getMongoDB();
        $definition = $this->indexDefinition();

        try {
            if ($this->searchIndexExists($database, self::INDEX_NAME)) {
                $database->command([
                    'updateSearchIndex' => self::COLLECTION,
                    'name' => self::INDEX_NAME,
                    'definition' => $definition,
                ]);

                return;
            }

            $database->command([
                'createSearchIndexes' => self::COLLECTION,
                'indexes' => [[
                    'name' => self::INDEX_NAME,
                    'definition' => $definition,
                ]],
            ]);
        } catch (MongoDriverException $exception) {
            if ($this->shouldIgnoreUnsupportedAtlasSearchCommand($exception)) {
                return;
            }

            throw $exception;
        }
    }

    public function down(): void
    {
        $database = DB::connection('tenant')->getMongoDB();

        try {
            if (! $this->searchIndexExists($database, self::INDEX_NAME)) {
                return;
            }

            $database->command([
                'dropSearchIndex' => self::COLLECTION,
                'name' => self::INDEX_NAME,
            ]);
        } catch (MongoDriverException $exception) {
            if ($this->shouldIgnoreUnsupportedAtlasSearchCommand($exception)) {
                return;
            }

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function indexDefinition(): array
    {
        return [
            'mappings' => [
                'dynamic' => false,
                'fields' => [
                    'title' => [
                        'type' => 'string',
                        'analyzer' => 'lucene.portuguese',
                    ],
                    'content' => [
                        'type' => 'string',
                        'analyzer' => 'lucene.portuguese',
                    ],
                    'artists' => [
                        'type' => 'document',
                        'fields' => [
                            'display_name' => [
                                'type' => 'string',
                                'analyzer' => 'lucene.portuguese',
                            ],
                        ],
                    ],
                    'venue' => [
                        'type' => 'document',
                        'fields' => [
                            'display_name' => [
                                'type' => 'string',
                                'analyzer' => 'lucene.portuguese',
                            ],
                        ],
                    ],
                    'geo_location' => [
                        'type' => 'geo',
                    ],
                ],
            ],
        ];
    }

    private function searchIndexExists(Database $database, string $name): bool
    {
        $cursor = $database->command([
            'listSearchIndexes' => self::COLLECTION,
            'name' => $name,
        ]);

        foreach ($cursor as $document) {
            if (($document->name ?? null) === $name) {
                return true;
            }
        }

        return false;
    }

    private function shouldIgnoreUnsupportedAtlasSearchCommand(MongoDriverException $exception): bool
    {
        $message = strtolower($exception->getMessage());
        $unsupported = str_contains($message, 'no such command')
            || str_contains($message, 'search index commands are only supported')
            || str_contains($message, 'atlas search')
            || str_contains($message, 'command not found');

        if (! $unsupported) {
            return false;
        }

        $strict = filter_var((string) env('EVENTS_ATLAS_SEARCH_INDEX_REQUIRED', 'false'), FILTER_VALIDATE_BOOL);
        if ($strict) {
            return false;
        }

        Log::warning('events_atlas_search_index_migration_skipped', [
            'collection' => self::COLLECTION,
            'index' => self::INDEX_NAME,
            'reason' => $exception->getMessage(),
            'strict' => false,
        ]);

        return true;
    }
};
