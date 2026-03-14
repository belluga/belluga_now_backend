<?php

declare(strict_types=1);

namespace Belluga\Invites\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class InviteShareCode extends Model
{
    use UsesTenantConnection;

    protected $table = 'invite_share_codes';

    protected $fillable = [
        'code',
        'event_id',
        'occurrence_id',
        'inviter_principal',
        'issued_by_user_id',
        'account_profile_id',
        'inviter_display_name',
        'inviter_avatar_url',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
