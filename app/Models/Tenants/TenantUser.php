<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use MongoDB\Laravel\Eloquent\DocumentModel;
use MongoDB\Laravel\Eloquent\SoftDeletes;
use MongoDB\Laravel\Relations\MorphMany;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class TenantUser extends Authenticatable
{
    use HasApiTokens, Notifiable, UsesTenantConnection, DocumentModel, SoftDeletes;

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

    public function createdModules(): MorphMany
    {
        return $this->morphMany(Module::class, 'creator');
    }
}
