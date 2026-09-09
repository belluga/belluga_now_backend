<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Historical cutover is intentionally no longer automated. Runtime
        // code neither reads nor mutates the retired relationship authorities.
    }

    public function down(): void
    {
        // Canonical historical storage is intentionally not reverted.
    }
};
