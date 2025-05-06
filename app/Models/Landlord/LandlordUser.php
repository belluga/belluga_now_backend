<?php

declare(strict_types=1);

namespace App\Models\Landlord;

use App\Services\TenantSessionManager;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use MongoDB\Laravel\Eloquent\DocumentModel;
use MongoDB\Laravel\Relations\BelongsToMany;
use MongoDB\Laravel\Relations\EmbedsMany;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

class LandlordUser extends Authenticatable
{
    use HasApiTokens, Notifiable, UsesLandlordConnection, DocumentModel;

    protected string $guard = 'landlord';

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class);
    }

    public function tenantRoles(): EmbedsMany
    {
        return $this->embedsMany(LandlordTenantRole::class);
    }

    public function getCurrentTenantRole()
    {
        $currentTenantId = app(TenantSessionManager::class)->getCurrentTenantId();

        if (!$currentTenantId) {
            return null;
        }

        return $this->tenantRoles->where('tenant_id', $currentTenantId)->first();
    }
}
