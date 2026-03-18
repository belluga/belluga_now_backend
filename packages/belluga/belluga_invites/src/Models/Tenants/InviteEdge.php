<?php

declare(strict_types=1);

namespace Belluga\Invites\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class InviteEdge extends Model
{
    use UsesTenantConnection;

    protected $table = 'invite_edges';

    protected $fillable = [
        'event_id',
        'occurrence_id',
        'receiver_user_id',
        'receiver_contact_hash',
        'inviter_principal',
        'account_profile_id',
        'issued_by_user_id',
        'inviter_display_name',
        'inviter_avatar_url',
        'status',
        'supersession_reason',
        'credited_acceptance',
        'source',
        'message',
        'event_name',
        'event_slug',
        'event_date',
        'event_image_url',
        'location_label',
        'host_name',
        'tags',
        'attendance_policy',
        'expires_at',
        'accepted_at',
        'declined_at',
    ];

    protected $casts = [
        'credited_acceptance' => 'boolean',
        'event_date' => 'datetime',
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'declined_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
