<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use App\Traits\HasPermissions;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use MongoDB\Laravel\Eloquent\DocumentModel;
use MongoDB\Laravel\Relations\EmbedsMany;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class TenantUser extends Authenticatable
{
    use HasApiTokens, Notifiable, UsesTenantConnection, DocumentModel, HasPermissions;

    protected $connection = 'tenants';

    protected $fillable = [
        'name',
        'email',
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

    public function accountRoles(): EmbedsMany
    {
        return $this->embedsMany(AccountRole::class);
    }

    public function getCurrentAccountRole()
    {
        $currentAccountId = app(\App\Services\AccountSessionManager::class)->getCurrentAccountId();

        if (!$currentAccountId) {
            return null;
        }

        return $this->accountRoles->where('account_id', $currentAccountId)->first();
    }
}
