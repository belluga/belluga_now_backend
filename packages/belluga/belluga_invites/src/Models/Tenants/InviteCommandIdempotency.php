<?php

declare(strict_types=1);

namespace Belluga\Invites\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class InviteCommandIdempotency extends Model
{
    use UsesTenantConnection;

    protected $table = 'invite_command_idempotencies';

    protected $fillable = [
        'command',
        'actor_user_id',
        'idempotency_key',
        'command_fingerprint',
        'response_payload',
    ];

    protected $casts = [
        'response_payload' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
