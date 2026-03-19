<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class AttendanceCommitment extends Model
{
    use UsesTenantConnection;

    protected $table = 'attendance_commitments';

    protected $fillable = [
        'user_id',
        'event_id',
        'occurrence_id',
        'kind',
        'status',
        'source',
        'confirmed_at',
        'canceled_at',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
        'canceled_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
