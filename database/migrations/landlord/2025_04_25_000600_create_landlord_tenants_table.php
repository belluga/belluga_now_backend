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
            $collection->unique("app_domains");
            $collection->index('user_ids');
            $collection->index(['created_at' => -1]);
            $collection->index([ "updated_at" => -1]);
            $collection->timestamps();
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
