<?php

use Illuminate\Database\Migrations\Migration;
use MongoDB\Laravel\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'mongodb';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $collection) {
            $collection->unique('emails');
            $collection->index('tenant_ids');
            $collection->index(['created_at' => -1, "updated_at" => -1], );
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->unique('email');
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->unique('id');
            $table->sparse('user_id');
            $table->index(['last_activity', -1]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
