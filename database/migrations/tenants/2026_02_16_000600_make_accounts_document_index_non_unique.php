<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('accounts', static function (Blueprint $collection): void {
            $collection->dropIndexIfExists(['document' => 1]);
            $collection->index(['document' => 1]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', static function (Blueprint $collection): void {
            $collection->dropIndexIfExists(['document' => 1]);
            $collection->unique('document');
        });
    }
};
