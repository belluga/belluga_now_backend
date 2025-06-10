<?php

declare(strict_types=1);

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use MongoDB\Laravel\Eloquent\DocumentModel;
use MongoDB\Laravel\Eloquent\SoftDeletes;
use App\Models\Landlord\Role as LandlordRole;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

class LandlordUser extends Authenticatable
{
    use HasApiTokens, Notifiable, SoftDeletes, DocumentModel, UsesLandlordConnection;

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

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(LandlordRole::class);
    }

    /**
     * Check if user has permission
     */
    public function hasPermissionTo(string $permission): bool
    {
        $permissions = $this->getAllPermissions();

        $parts = explode('.', $permission, 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$resource, $action] = $parts;

        return in_array("*", $permissions) ||
            in_array("*.$action", $permissions) ||
            in_array("$resource.*", $permissions) ||
            in_array("$resource.$action", $permissions);
    }

    /**
     * Get all permissions from all roles
     */
    public function getAllPermissions(): array
    {
        return $this->role()
            ->get()
            ->pluck('permissions')
            ->flatten()
            ->unique()
            ->toArray();
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
