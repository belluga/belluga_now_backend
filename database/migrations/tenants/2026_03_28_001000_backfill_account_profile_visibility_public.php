<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasCollection('account_profiles')) {
            return;
        }

        // Preserve strict public-only query behavior by normalizing legacy rows.
        $collection = DB::connection('tenant')
            ->getMongoDB()
            ->selectCollection('account_profiles');
        $collection->updateMany(
            [
                '$or' => [
                    ['visibility' => ['$exists' => false]],
                    ['visibility' => null],
                    ['visibility' => ''],
                ],
            ],
            [
                '$set' => ['visibility' => 'public'],
            ],
        );

        Schema::table('account_profiles', function (Blueprint $collection): void {
            $collection->index(['visibility' => 1]);
            $collection->index(['visibility' => 1, 'is_active' => 1, 'profile_type' => 1]);
        });
    }

    public function down(): void
    {
        // no-op: normalization migration
    }
};
