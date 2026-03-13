<?php

declare(strict_types=1);

namespace Belluga\Invites\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class InviteQuotaCounter extends Model
{
    use UsesTenantConnection;

    protected $table = 'invite_quota_counters';

    protected $fillable = [
        'scope',
        'scope_id',
        'window_key',
        'count',
    ];

    protected $casts = [
        'count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
