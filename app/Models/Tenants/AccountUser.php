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
//        'access_roles' => AsArrayObject::class,
    ];

    public function attachAccount(Account $item, Role $role):void {
        if(!in_array($item, $this->getAccessToIds())){
            $this->push(
                "access_roles",
                [
                    "item_id" => new ObjectId($item->id),
                    "item_type" => get_class($item),
                    "role_id" => new ObjectId($role->id),
                    "role_slug" => $role->slug,
                    "role_type" => get_class($role),
                    "permissions" => $role->permissions,
                ]
            );
            $this->refresh();
        }
    }

    public function detachAccessItem(Account $account):void {
        if(in_array($account->id, $this->getAccessToIds())){
            $this->pull($this->haveAccessToKey, $account->id);
        }
    }

    public function hasAccessTo(Account $account):bool {
        return in_array($account->id, $this->getAccessToIds());
    }

    public function getAccessToIds(): array{
        return collect($this->access_roles)
            ->where('item_type', "==", Account::class)
            ->pluck('item_id')
            ->toArray() ?? [];
    }

    public function getPermissions(?Account $account = null): array
    {
        $account = $account ?? Account::current();

        return collect($this->access_roles)
            ->where('item_id', $account->id)
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
