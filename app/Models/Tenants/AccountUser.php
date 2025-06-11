<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use App\Traits\OwnAccounts;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use MongoDB\Laravel\Eloquent\DocumentModel;
use MongoDB\Laravel\Eloquent\SoftDeletes;
use MongoDB\Laravel\Relations\BelongsToMany;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class AccountUser extends Authenticatable
{
    use HasApiTokens, Notifiable, UsesTenantConnection, DocumentModel, SoftDeletes, OwnAccounts;

    protected $table = 'users';

    protected $fillable = [
        'name',
        'emails',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function accounts(): BelongsToMany {
        return $this->belongsToMany(AccountUser::class);
    }

    public function role(): BelongsTo {
        return $this->belongsTo(Role::class);
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

    public function tokenCan(string $ability): bool
    {

        $permissions = $this->getAllPermissions();

        $parts = explode(':', $ability, 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$resource, $action] = $parts;

        return in_array("*", $permissions) ||
            in_array("$resource:*", $permissions) ||
            in_array("$resource:$action", $permissions);
    }

    public function getAllPermissions(): array
    {
        return $this->role()
            ->get()
            ->pluck('permissions')
            ->flatten()
            ->unique()
            ->toArray();
    }
}
