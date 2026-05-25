<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inviteable_people_projection', function (Blueprint $collection): void {
            $collection->unique(['owner_user_id' => 1, 'receiver_account_profile_id' => 1]);
            $collection->index(['owner_user_id' => 1, 'sort_name' => 1, 'receiver_account_profile_id' => 1]);
            $collection->index(['owner_user_id' => 1, 'is_inviteable' => 1, 'sort_name' => 1, 'receiver_account_profile_id' => 1]);
            $collection->index(['owner_user_id' => 1, 'contact_hash' => 1, 'receiver_account_profile_id' => 1]);
            $collection->index(['receiver_user_id' => 1, 'owner_user_id' => 1]);
            $collection->index(['materialized_at' => -1, '_id' => 1]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inviteable_people_projection');
    }
};
