<?php

declare(strict_types=1);

namespace App\Models\Landlord;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

class LandlordTenantRole extends Model
{
    use UsesLandlordConnection;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'role_name',
        'permissions'
    ];

    protected $casts = [
        'permissions' => 'array'
    ];

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions);
    }
}
