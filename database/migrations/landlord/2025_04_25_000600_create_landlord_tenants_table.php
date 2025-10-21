<?php

use Illuminate\Database\Migrations\Migration;
use MongoDB\Laravel\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $collection) {
            $collection->unique('slug');
            $collection->unique("subdomain");
            $collection->index('user_ids');
            $collection->index(['created_at' => -1]);
            $collection->index([ "updated_at" => -1]);
            $collection->timestamps();

            $collection->index(
                ['app_domains' => 1],
                options: [
                    'unique' => true,
                    'name' => 'unique_appdomains_if_present',
                    'partialFilterExpression' => [
                        'app_domains.0' => ['$exists' => true]
                    ]
                ]);

            $collection->index(
                ['domains' => 1],
                options: [
                    'unique' => true,
                    'name' => 'unique_domains_if_present',
                    'partialFilterExpression' => [
                        'domains.0' => ['$exists' => true]
                    ]
                ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
