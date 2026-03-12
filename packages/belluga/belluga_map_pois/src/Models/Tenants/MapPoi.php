<?php

declare(strict_types=1);

namespace Belluga\MapPois\Models\Tenants;

use Illuminate\Support\Carbon;
use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class MapPoi extends Model
{
    use UsesTenantConnection;

    protected $table = 'map_pois';

    protected $fillable = [
        'ref_type',
        'ref_id',
        'projection_key',
        'source_checkpoint',
        'ref_slug',
        'ref_path',
        'name',
        'subtitle',
        'category',
        'source_type',
        'tags',
        'taxonomy_terms',
        'taxonomy_terms_flat',
        'location',
        'discovery_scope',
        'occurrence_facets',
        'is_happening_now',
        'priority',
        'is_active',
        'active_window_start_at',
        'active_window_end_at',
        'time_start',
        'time_end',
        'avatar_url',
        'cover_url',
        'badge',
        'exact_key',
    ];

    protected $casts = [
        'source_checkpoint' => 'integer',
        'is_happening_now' => 'bool',
        'is_active' => 'bool',
        'active_window_start_at' => 'datetime',
        'active_window_end_at' => 'datetime',
        'time_start' => 'datetime',
        'time_end' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $model): void {
            $refType = (string) ($model->getAttribute('ref_type') ?? '');
            $refId = (string) ($model->getAttribute('ref_id') ?? '');

            if (($model->getAttribute('projection_key') ?? null) === null && $refType !== '' && $refId !== '') {
                $model->setAttribute('projection_key', "{$refType}:{$refId}");
            }

            if (($model->getAttribute('source_checkpoint') ?? null) === null) {
                $model->setAttribute('source_checkpoint', (int) Carbon::now()->valueOf());
            }
        });
    }
}
