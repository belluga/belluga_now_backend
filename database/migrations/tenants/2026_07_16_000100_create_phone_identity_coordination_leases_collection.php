<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_identity_coordination_leases', static function (Blueprint $collection): void {
            $collection->index(
                ['lease_expires_at' => 1],
                options: ['name' => 'idx_phone_identity_coordination_lease_expiry_v1']
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_identity_coordination_leases');
    }
};
