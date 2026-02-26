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
        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $collection): void {
                $collection->index(['created_at' => -1]);
                $collection->index(['updated_at' => -1]);
            });
        }

        $connectionName = (string) config('multitenancy.tenant_database_connection_name', 'tenant');
        $collection = DB::connection($connectionName)->getMongoDB()->selectCollection('settings');

        $count = (int) $collection->countDocuments([]);
        if ($count > 1) {
            throw new RuntimeException('Settings migration failed: more than one settings document found in tenant scope.');
        }

        if ($count === 1) {
            $existing = $collection->findOne([]);
            if (! $existing) {
                return;
            }

            $existingId = (string) ($existing['_id'] ?? '');
            if ($existingId === SettingsDocument::ROOT_ID) {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
