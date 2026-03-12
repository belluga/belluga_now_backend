<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Facades\DB;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasCollection('account_profile_types')) {
            Schema::create('account_profile_types', function (Blueprint $collection) {
                $collection->unique('type');
                $collection->index(['capabilities.is_favoritable' => 1]);
                $collection->index(['capabilities.is_poi_enabled' => 1]);
                $collection->index(['created_at' => -1, 'updated_at' => -1]);
            });
        }

        if (Schema::hasCollection('profile_types')) {
            $cursor = DB::connection('tenant')
                ->collection('profile_types')
                ->get();

            if ($cursor instanceof \Illuminate\Support\Collection) {
                $records = $cursor->all();
            } else {
                $records = is_iterable($cursor) ? iterator_to_array($cursor) : [];
            }

            foreach ($records as $record) {
                if (is_array($record)) {
                    $document = $record;
                } elseif (is_object($record)) {
                    $document = (array) $record;
                } else {
                    continue;
                }

                unset($document['_id']);

                $type = $document['type'] ?? null;
                if (! is_string($type) || trim($type) === '') {
                    continue;
                }

                DB::connection('tenant')
                    ->collection('account_profile_types')
                    ->updateOrInsert(
                        ['type' => $type],
                        $document
                    );
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_profile_types');
    }
};
