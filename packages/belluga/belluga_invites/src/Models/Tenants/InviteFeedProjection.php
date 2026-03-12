<?php

declare(strict_types=1);

namespace Belluga\Invites\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class InviteFeedProjection extends Model
{
    use UsesTenantConnection;

    protected $table = 'invite_feed_projection';

    protected $fillable = [
        'receiver_user_id',
        'group_key',
        'event_id',
        'occurrence_id',
        'event_name',
        'event_slug',
        'event_date',
        'event_image_url',
        'location',
        'host_name',
        'message',
        'tags',
        'attendance_policy',
        'inviter_candidates',
        'social_proof',
    ];

    protected $casts = [
        'event_date' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
