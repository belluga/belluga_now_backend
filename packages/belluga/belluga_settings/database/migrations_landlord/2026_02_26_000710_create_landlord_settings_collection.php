<?php

declare(strict_types=1);

use Belluga\Settings\Models\SettingsDocument;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        $connectionName = (string) config('multitenancy.landlord_database_connection_name', 'landlord');

        if (! Schema::connection($connectionName)->hasTable('settings')) {
            Schema::connection($connectionName)->create('settings', function (Blueprint $collection): void {
                $collection->index(['created_at' => -1]);
                $collection->index(['updated_at' => -1]);
            });
        }

        $collection = DB::connection($connectionName)->getMongoDB()->selectCollection('settings');
        $count = (int) $collection->countDocuments([]);

        if ($count > 1) {
            throw new RuntimeException('Settings migration failed: more than one settings document found in landlord scope.');
        }

        if ($count === 1) {
            $existing = $collection->findOne([]);
            if (! $existing) {
                $this->enforceRootIdValidator($connectionName);
                return;
            }

            $existingId = (string) ($existing['_id'] ?? '');
            if ($existingId === SettingsDocument::ROOT_ID) {
                $this->enforceRootIdValidator($connectionName);
                return;
            }

            $data = $existing->getArrayCopy();
            unset($data['_id']);

            $collection->replaceOne(
                ['_id' => SettingsDocument::ROOT_ID],
                array_merge(['_id' => SettingsDocument::ROOT_ID], $data),
                ['upsert' => true]
            );

            $collection->deleteOne(['_id' => $existing['_id']]);
        }

        $this->enforceRootIdValidator($connectionName);
    }

    public function down(): void
    {
        $connectionName = (string) config('multitenancy.landlord_database_connection_name', 'landlord');
        Schema::connection($connectionName)->dropIfExists('settings');
    }

    private function enforceRootIdValidator(string $connectionName): void
    {
        $validator = [
            '$expr' => [
                '$eq' => ['$_id', SettingsDocument::ROOT_ID],
            ],
        ];

        try {
            DB::connection($connectionName)->getMongoDB()->command([
                'collMod' => 'settings',
                'validator' => $validator,
                'validationLevel' => 'strict',
                'validationAction' => 'error',
            ]);
        } catch (\Throwable $throwable) {
            throw new RuntimeException(
                sprintf('Settings migration failed while enforcing singleton validator on [%s] connection.', $connectionName),
                previous: $throwable
            );
        }
    }
};
