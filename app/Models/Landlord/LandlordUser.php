<?php

declare(strict_types=1);

namespace App\Models\Landlord;

use App\Traits\HaveMultipleEmails;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\DocumentModel;
use MongoDB\Laravel\Eloquent\SoftDeletes;
use MongoDB\Laravel\Relations\BelongsTo;
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
//        'access_roles' => AsArrayObject::class,
    ];

    public function role(): BelongsTo {
        return $this->belongsTo(LandlordRole::class);
    }

    public function attachTenant(Tenant $tenant, TenantRole $role):void {
        if(!in_array($tenant->id, $this->getAccessToIds())){
            $this->push(
                "access_roles",
                [
                    "item_id" => new ObjectId($tenant->id),
                    "item_type" => get_class($tenant),
                    "role_id" => new ObjectId($role->id),
                    "role_slug" => $role->slug,
                    "role_type" => get_class($role),
                    "permissions" => $role->permissions,
                ]
            );
            $this->refresh();
        }
    }

    public function detachTenant(Tenant $tenant):void {
        if(in_array($tenant, $this->getAccessToIds())){
            $this->pull($this->haveAccessToKey, $tenant->id);
        }
    }

    public function hasAccessTo(Tenant $tenant):bool {
        return in_array($tenant->id, $this->getAccessToIds());
    }

    public function getAccessToIds(): array{

        $access_array = $this->access_roles ?? [];

        return collect($access_array)
            ->where('item_type', "==", Tenant::class)
            ->pluck('item_id')
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

        return collect($this->access_roles)
            ->where('item_id', $tenant->id)
            ->pluck('permissions')
            ->flatten()
            ->unique()
            ->toArray();

    }

    protected function getLandlordPermissions(): array
    {
        $role = LandlordRole::find($this->landlord_role_id)->first();

        return $role
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
