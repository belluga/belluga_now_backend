<?php

declare(strict_types=1);

use Belluga\Events\Models\Tenants\EventOccurrence;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    private const DEFAULT_EVENT_DURATION_HOURS = 3;

    public function up(): void
    {
        Schema::table('event_occurrences', function (Blueprint $collection): void {
            $collection->index([
                'deleted_at' => 1,
                'is_event_published' => 1,
                'place_ref.type' => 1,
                'place_ref.id' => 1,
                'effective_ends_at' => 1,
                'starts_at' => 1,
                '_id' => 1,
            ]);

            $collection->index([
                'deleted_at' => 1,
                'is_event_published' => 1,
                'artists.id' => 1,
                'effective_ends_at' => 1,
                'starts_at' => 1,
                '_id' => 1,
            ]);
        });

        EventOccurrence::withTrashed()
            ->orderBy('_id')
            ->cursor()
            ->each(function (EventOccurrence $occurrence): void {
                $start = $occurrence->starts_at;
                if (! $start instanceof Carbon) {
                    return;
                }

                $end = $occurrence->ends_at instanceof Carbon
                    ? $occurrence->ends_at
                    : $start->copy()->addHours(self::DEFAULT_EVENT_DURATION_HOURS);

                $occurrence->forceFill([
                    'effective_ends_at' => $end,
                ])->saveQuietly();
            });
    }

    public function down(): void
    {
        Schema::table('event_occurrences', function (Blueprint $collection): void {
            $collection->dropIndex([
                'deleted_at' => 1,
                'is_event_published' => 1,
                'place_ref.type' => 1,
                'place_ref.id' => 1,
                'effective_ends_at' => 1,
                'starts_at' => 1,
                '_id' => 1,
            ]);

            $collection->dropIndex([
                'deleted_at' => 1,
                'is_event_published' => 1,
                'artists.id' => 1,
                'effective_ends_at' => 1,
                'starts_at' => 1,
                '_id' => 1,
            ]);
        });
    }
};
