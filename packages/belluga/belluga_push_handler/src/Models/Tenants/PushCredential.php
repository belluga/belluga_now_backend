<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class PushCredential extends Model
{
    use UsesTenantConnection;

    protected $collection = 'push_credentials';

    protected $fillable = [
        'project_id',
        'client_email',
        'private_key',
    ];

    protected $hidden = [
        'private_key',
    ];

    protected $casts = [
        'project_id' => 'string',
        'client_email' => 'string',
        'private_key' => 'encrypted',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
