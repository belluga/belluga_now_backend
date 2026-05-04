<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_otp_challenges', static function (Blueprint $collection): void {
            $collection->index(
                ['phone' => 1, 'status' => 1, 'expires_at' => 1],
                options: ['name' => 'idx_phone_otp_challenges_phone_status_expiry_v1']
            );
            $collection->index(
                ['phone_hash' => 1],
                options: ['name' => 'idx_phone_otp_challenges_phone_hash_v1']
            );
            $collection->index(
                ['created_at' => -1],
                options: ['name' => 'idx_phone_otp_challenges_created_at_v1']
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_otp_challenges');
    }
};
