<?php

declare(strict_types=1);

namespace Belluga\Invites\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class PrincipalSocialMetric extends Model
{
    use UsesTenantConnection;

    protected $table = 'principal_social_metrics';

    protected $fillable = [
        'principal_kind',
        'principal_id',
        'invites_sent',
        'credited_invite_acceptances',
        'pending_invites_received',
    ];

    protected $casts = [
        'invites_sent' => 'integer',
        'credited_invite_acceptances' => 'integer',
        'pending_invites_received' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
