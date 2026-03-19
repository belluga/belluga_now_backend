<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invite_command_idempotencies', function (Blueprint $collection): void {
            $collection->unique(
                ['command' => 1, 'actor_user_id' => 1, 'idempotency_key' => 1],
                options: ['name' => 'uq_invite_command_idempotency_scope']
            );
            $collection->index(['updated_at' => -1, '_id' => 1]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invite_command_idempotencies');
    }
};
