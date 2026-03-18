<?php

declare(strict_types=1);

use App\Models\Landlord\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $collection = DB::connection('landlord')
            ->getMongoDB()
            ->selectCollection('domains');

        $collection->updateMany(
            ['type' => ['$exists' => false]],
            ['$set' => ['type' => Tenant::DOMAIN_TYPE_WEB]]
        );
        $collection->updateMany(
            ['type' => null],
            ['$set' => ['type' => Tenant::DOMAIN_TYPE_WEB]]
        );

        foreach ($collection->listIndexes() as $index) {
            $name = (string) ($index['name'] ?? '');
            $key = $index['key'] ?? null;
            if (! is_array($key) || $name === '_id_') {
                continue;
            }

            if (array_keys($key) === ['path']) {
                $collection->dropIndex($name);
            }
        }

        $collection->createIndex(
            ['path' => 1, 'type' => 1],
            [
                'name' => 'unique_domain_path_type',
                'unique' => true,
            ]
        );
    }

    public function down(): void
    {
        $collection = DB::connection('landlord')
            ->getMongoDB()
            ->selectCollection('domains');

        try {
            $collection->dropIndex('unique_domain_path_type');
        } catch (\Throwable) {
            // no-op
        }

        try {
            $collection->createIndex(
                ['path' => 1],
                [
                    'name' => 'path_1',
                    'unique' => true,
                ]
            );
        } catch (\Throwable) {
            // no-op
        }
    }
};
