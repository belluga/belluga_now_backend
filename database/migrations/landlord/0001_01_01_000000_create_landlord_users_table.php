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
            $collection->index('landlord_role_id');
            $collection->index('tenant_roles.slug');
            $collection->index('tenant_roles.tenant_id');
            $collection->index(['created_at' => -1, "updated_at" => -1], );

            $collection->index(
                ['emails' => 1],
                options: [
                    'unique' => true,
                    'name' => 'unique_emails_if_present',
                    'partialFilterExpression' => [
                        'emails.0' => ['$exists' => true]
                    ]
                ]);

            $collection->index(
                ['phones' => 1],
                options: [
                    'unique' => true,
                    'name' => 'unique_phones_if_present',
                    'partialFilterExpression' => [
                        'phones.0' => ['$exists' => true]
                    ]
                ]);
        });

        Schema::create('password_reset_tokens', function (Blueprint $collection) {
            $collection->unique('user_id');
            $collection->index('token');
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
