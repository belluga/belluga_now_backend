<?php

declare(strict_types=1);

namespace Belluga\Invites\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class InviteOutboxEvent extends Model
{
    use UsesTenantConnection;

    protected $table = 'invite_outbox_events';

    protected $fillable = [
        'topic',
        'status',
        'receiver_user_id',
        'payload',
        'dedupe_key',
        'available_at',
        'processed_at',
        'attempts',
        'last_error',
    ];

    protected $casts = [
        'available_at' => 'datetime',
        'processed_at' => 'datetime',
        'attempts' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
