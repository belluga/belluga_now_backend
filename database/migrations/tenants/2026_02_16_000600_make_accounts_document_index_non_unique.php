<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string INDEX_NAME = 'document_1';

    public function up(): void
    {
        if (! Schema::hasCollection('accounts')) {
            return;
        }

        $collection = DB::connection('tenant')->getCollection('accounts');

        $this->dropIndexIfExists($collection);
        $collection->createIndex(['document' => 1], ['name' => self::INDEX_NAME]);
    }

    public function down(): void
    {
        if (! Schema::hasCollection('accounts')) {
            return;
        }

        $collection = DB::connection('tenant')->getCollection('accounts');

        $this->dropIndexIfExists($collection);
        $collection->createIndex(['document' => 1], [
            'name' => self::INDEX_NAME,
            'unique' => true,
        ]);
    }

    private function dropIndexIfExists(object $collection): void
    {
        try {
            $collection->dropIndex(self::INDEX_NAME);
        } catch (\Throwable) {
            // Fresh and already-repaired databases may not have the legacy index.
        }
    }
};
