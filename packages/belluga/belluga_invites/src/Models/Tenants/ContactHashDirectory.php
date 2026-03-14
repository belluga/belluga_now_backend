<?php

declare(strict_types=1);

namespace Belluga\Invites\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class ContactHashDirectory extends Model
{
    use UsesTenantConnection;

    protected $table = 'contact_hash_directory';

    protected $fillable = [
        'importing_user_id',
        'contact_hash',
        'type',
        'salt_version',
        'matched_user_id',
        'match_snapshot',
        'imported_at',
        'last_seen_at',
    ];

    protected $casts = [
        'imported_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
