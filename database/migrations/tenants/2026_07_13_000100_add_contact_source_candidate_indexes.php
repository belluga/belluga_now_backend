<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MongoDB\Collection;
use MongoDB\Driver\Exception\CommandException;
use MongoDB\Laravel\Schema\Blueprint;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

return new class extends Migration
{
    private const ACCOUNT_PROFILE_INDEX_NAME = 'idx_account_profiles_contact_source_candidates_v1';

    private const ACCOUNT_PROFILE_INDEX_KEY = [
        'contact_mode' => 1,
        'is_active' => 1,
        'deleted_at' => 1,
        'profile_type' => 1,
        'display_name' => 1,
        '_id' => 1,
    ];

    public function up(): void
    {
        if (! $this->hasAccountProfileTypeIndex([
            'capabilities.has_contact_channels' => 1,
            'type' => 1,
        ])) {
            Schema::table('account_profile_types', static function (Blueprint $collection): void {
                $collection->index(
                    [
                        'capabilities.has_contact_channels' => 1,
                        'type' => 1,
                    ],
                    options: ['name' => 'idx_account_profile_types_contact_channels_v1'],
                );
            });
        }

        if (! Schema::hasCollection('account_profiles')) {
            return;
        }

        $this->dropAccountProfileIndexIfPresent();
        Schema::table('account_profiles', static function (Blueprint $collection): void {
            $collection->index(
                self::ACCOUNT_PROFILE_INDEX_KEY,
                options: ['name' => self::ACCOUNT_PROFILE_INDEX_NAME],
            );
        });
    }

    public function down(): void
    {
        Schema::table('account_profiles', static function (Blueprint $collection): void {
            $collection->dropIndexIfExists(self::ACCOUNT_PROFILE_INDEX_KEY);
        });

        Schema::table('account_profile_types', static function (Blueprint $collection): void {
            $collection->dropIndexIfExists([
                'capabilities.has_contact_channels' => 1,
                'type' => 1,
            ]);
        });
    }

    /**
     * @param  array<string, int>  $keys
     */
    private function hasAccountProfileTypeIndex(array $keys): bool
    {
        /** @var Collection<array<string, mixed>> $collection */
        $collection = DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profile_types');

        foreach ($collection->listIndexes() as $index) {
            if ($this->arrayFrom($index->getKey()) === $keys) {
                return true;
            }
        }

        return false;
    }

    private function dropAccountProfileIndexIfPresent(): void
    {
        /** @var Collection<array<string, mixed>> $collection */
        $collection = DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profiles');

        foreach ($collection->listIndexes() as $index) {
            $shouldDrop = $index->getName() === self::ACCOUNT_PROFILE_INDEX_NAME
                || $this->arrayFrom($index->getKey()) === self::ACCOUNT_PROFILE_INDEX_KEY;
            if (! $shouldDrop) {
                continue;
            }

            $this->dropIndexIgnoringMissing($collection, (string) $index->getName());
        }
    }

    private function dropIndexIgnoringMissing(Collection $collection, string $name): void
    {
        try {
            $collection->dropIndex($name);
        } catch (CommandException $exception) {
            if ($exception->getCode() !== 27) {
                throw $exception;
            }
        }
    }

    /**
     * @return array<string, int>
     */
    private function arrayFrom(mixed $value): array
    {
        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            return $value->getArrayCopy();
        }

        return is_array($value) ? $value : [];
    }
};
