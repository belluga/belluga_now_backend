<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class InviteStageTestSupportRun extends Model
{
    use UsesTenantConnection;

    protected $table = 'invite_stage_test_support_runs';

    protected $fillable = [
        'run_id',
        'scenario',
        'tenant_slug',
        'event_id',
        'occurrence_id',
        'share_code',
        'invite_url',
        'refs',
        'credentials',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
