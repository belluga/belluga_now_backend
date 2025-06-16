<?php

declare(strict_types=1);

namespace App\Models\Landlord;

use App\Traits\HaveMultipleEmails;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use MongoDB\Laravel\Eloquent\DocumentModel;
use MongoDB\Laravel\Eloquent\SoftDeletes;
use MongoDB\Laravel\Relations\BelongsTo;
use MongoDB\Laravel\Relations\EmbedsMany;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

class LandlordUser extends Authenticatable {

    use HasApiTokens, Notifiable, SoftDeletes, DocumentModel, UsesLandlordConnection, HaveMultipleEmails;

    protected $table = 'landlord_users';

    protected $fillable = [
        'name',
        'emails',
        'password'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function landlordRole(): BelongsTo {
        return $this->belongsTo(LandlordRole::class);
    }

    public function tenantRoles(): EmbedsMany {
        return $this->embedsMany(TenantRole::class, 'tenant_roles');
    }

    public function getAccessToIds(): array{

        $tenant_roles_array = $this->tenant_roles ?? [];

        return collect($tenant_roles_array)
            ->pluck('tenant_id')
            ->toArray();
    }

    public function getPermissions(?Tenant $tenant = null): array
    {
        $tenant = $tenant ?? Tenant::current();

        if($tenant){
            return $this->getTenantPermissions();
        }

        return $this->getLandlordPermissions();
    }

    protected function getTenantPermissions(?Tenant $tenant = null): array
    {
        $tenant = $tenant ?? Tenant::current();

        return collect($this->tenant_roles)
            ->where('tenant_id', "==", $tenant->id)
            ->pluck('permissions')
            ->flatten()
            ->unique()
            ->toArray();

    }

    protected function getLandlordPermissions(): array
    {
        $role = LandlordRole::find($this->landlord_role_id)->first();
        return $role->permissions ?? [];
    }


    public function tokenCan(string $ability): bool
    {

        $permissions = $this->getPermissions();

        $parts = explode(':', $ability, 2);

        if (count($parts) !== 2) {
            return false;
        }
        [$resource, $action] = $parts;

        return in_array("*", $permissions) ||
            in_array("$resource:*", $permissions) ||
            in_array("$resource:$action", $permissions);
    }

}
