<?php

use App\Models\Tenants\TenantSettings;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        TenantSettings::query()
            ->raw(static function ($collection): void {
                $collection->updateMany([], ['$unset' => ['profile_type_registry' => true]]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No down migration for legacy removal.
    }
};
