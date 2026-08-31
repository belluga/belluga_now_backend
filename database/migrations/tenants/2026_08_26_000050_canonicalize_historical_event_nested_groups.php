<?php

declare(strict_types=1);

use Belluga\Events\Application\Events\LegacyEventPartiesCanonicalizationService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(LegacyEventPartiesCanonicalizationService::class)->repairNestedGroupsForCutover(
            failOnError: true,
        );
    }

    public function down(): void
    {
        // Canonical historical storage is intentionally not reverted.
    }
};
