<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use App\Traits\HaveMultipleEmails;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\DocumentModel;
use MongoDB\Laravel\Eloquent\SoftDeletes;
use MongoDB\Laravel\Relations\EmbedsMany;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class AccountUser extends Authenticatable {

    use HasApiTokens, Notifiable, SoftDeletes, DocumentModel, UsesTenantConnection, HaveMultipleEmails;

    protected $table = 'account_users';

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

    public function tenantRoles(): EmbedsMany {
        return $this->embedsMany(AccountRole::class, 'account_roles');
    }

    public function haveAccessTo(Account $account):bool {
        return in_array($account->id, $this->getAccessToIds());
    }

    public function getAccessToIds(): array{
        return collect($this->account_roles)
            ->pluck('account_id')
            ->toArray() ?? [];
    }

    public function getPermissions(?Account $account = null): array
    {
        $account = $account ?? Account::current();

        return collect($this->account_roles)
            ->where('account_id', "==", $account->id)
            ->pluck('permissions')
            ->flatten()
            ->unique()
            ->toArray();

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
