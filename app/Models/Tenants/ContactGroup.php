<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class ContactGroup extends Model
{
    use UsesTenantConnection;

    protected $table = 'contact_groups';

    protected $fillable = [
        'owner_user_id',
        'name',
        'recipient_account_profile_ids',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
