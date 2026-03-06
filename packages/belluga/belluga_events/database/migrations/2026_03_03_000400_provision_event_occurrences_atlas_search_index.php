<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // MVP decision:
        // Event text search is disabled; agenda filtering is taxonomy/category/tag + geo only.
        // Atlas Search index provisioning is intentionally not part of this release.
    }

    public function down(): void
    {
        // No-op by design. Migration retained for historical continuity.
    }
};
