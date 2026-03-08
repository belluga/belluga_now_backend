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
        Schema::create('api_abuse_signals', function (Blueprint $collection): void {
            $collection->index(['created_at' => -1]);
            $collection->index(['code' => 1, 'level' => 1, 'created_at' => -1]);
            $collection->index(['tenant_reference' => 1, 'created_at' => -1]);
            $collection->index(['principal_hash' => 1, 'created_at' => -1]);
        });

        Schema::create('api_abuse_signal_aggregates', function (Blueprint $collection): void {
            $collection->index(['bucket_at' => -1]);
            $collection->index(['code' => 1, 'bucket_at' => -1]);
            $collection->index(['tenant_reference' => 1, 'bucket_at' => -1]);
            $collection->index([
                'bucket_at' => 1,
                'code' => 1,
                'action' => 1,
                'level' => 1,
                'tenant_reference' => 1,
                'method' => 1,
                'path' => 1,
                'observe_mode' => 1,
            ]);
        });

        $landlordDb = DB::connection('landlord')->getMongoDB();

        $landlordDb->selectCollection('api_abuse_signals')->createIndex(
            ['expires_at' => 1],
            ['name' => 'expires_at_ttl', 'expireAfterSeconds' => 0],
        );

        $landlordDb->selectCollection('api_abuse_signal_aggregates')->createIndex(
            ['expires_at' => 1],
            ['name' => 'expires_at_ttl', 'expireAfterSeconds' => 0],
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('api_abuse_signal_aggregates');
        Schema::dropIfExists('api_abuse_signals');
    }
};
