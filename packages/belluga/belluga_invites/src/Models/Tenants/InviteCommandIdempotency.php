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
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * @return array<string, mixed>
     */
    public function getResponsePayloadAttribute(mixed $value): array
    {
        if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
            $decoded = json_decode(json_encode($value), true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }
}
