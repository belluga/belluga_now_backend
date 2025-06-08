<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use App\Traits\OwnAccounts;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use MongoDB\Laravel\Eloquent\DocumentModel;
use MongoDB\Laravel\Eloquent\SoftDeletes;
use MongoDB\Laravel\Relations\BelongsToMany;
use MongoDB\Laravel\Relations\HasOne;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class AccountUser extends Authenticatable
{
    use HasApiTokens, Notifiable, UsesTenantConnection, DocumentModel, SoftDeletes, OwnAccounts;

    protected $table = 'users';

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

    public function accounts(): BelongsToMany {
        return $this->belongsToMany(AccountUser::class);
    }

    public function role(): HasOne {
        return $this->hasOne(Role::class);
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
