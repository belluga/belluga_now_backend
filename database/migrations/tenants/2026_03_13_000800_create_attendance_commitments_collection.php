<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_commitments', function (Blueprint $collection): void {
            $collection->unique(
                [
                    'user_id' => 1,
                    'event_id' => 1,
                    'occurrence_id' => 1,
                ],
                options: [
                    'name' => 'uq_attendance_commitments_user_event_occurrence',
                ]
            );
            $collection->index(['user_id' => 1, 'status' => 1, 'confirmed_at' => -1, '_id' => 1]);
            $collection->index(['event_id' => 1, 'status' => 1, '_id' => 1]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_commitments');
    }
};
