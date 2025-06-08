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
        Schema::create('landlord_users', function (Blueprint $collection) {
            $collection->unique('emails');
            $collection->index('landlord_role_id');
            $collection->index('tenant_ids');
            $collection->index(['created_at' => -1, "updated_at" => -1], );
        });

        Schema::create('password_reset_tokens', function (Blueprint $collection) {
            $collection->unique('email');
        });

        Schema::create('sessions', function (Blueprint $collection) {
            $collection->unique('id');
            $collection->sparse('user_id');
            $collection->index(['last_activity', -1]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('landlord_users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
