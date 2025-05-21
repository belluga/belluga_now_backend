<?php

declare(strict_types=1);

namespace App\Models\Landlord;

use App\Models\Tenants\AccountUserRole;
use App\Traits\DemandPermissions;
use App\Traits\OwnAccounts;
use App\Traits\OwnRoles;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use MongoDB\Laravel\Eloquent\DocumentModel;
use MongoDB\Laravel\Eloquent\SoftDeletes;
use MongoDB\Laravel\Relations\BelongsToMany;
use MongoDB\Laravel\Relations\HasMany;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

class LandlordUser extends Authenticatable
{
    use HasApiTokens, Notifiable, UsesLandlordConnection, DocumentModel, SoftDeletes, DemandPermissions, OwnAccounts;

    protected $guarded = [
        'role'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function tenantRoles(): HasMany {
        return $this->hasMany(TenantUserRole::class);
    }

    public function landlordRole(): HasMany {
        return $this->hasMany(LandlordUserRole::class);
    }

    public function addEmail(string $email): void {
        $this->update(
            ['$push' => ['emails' => $email]]
        );
    }

    public function removeEmail(string $email): void {
        $this->update(
            [],
            ['$pull' => ['emails' => $email]]
        );
    }
}
