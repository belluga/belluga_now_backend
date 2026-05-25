<?php

declare(strict_types=1);

namespace Belluga\Invites\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class InviteablePeopleProjection extends Model
{
    use UsesTenantConnection;

    protected $table = 'inviteable_people_projection';

    protected $fillable = [
        'owner_user_id',
        'receiver_user_id',
        'receiver_account_profile_id',
        'display_name',
        'avatar_url',
        'cover_url',
        'profile_type',
        'profile_exposure_level',
        'inviteable_reasons',
        'source_tags',
        'is_inviteable',
        'contact_hash',
        'contact_type',
        'sort_name',
        'materialized_at',
    ];

    protected $casts = [
        'is_inviteable' => 'boolean',
        'materialized_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
