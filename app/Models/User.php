<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\HasAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Sanctum\HasApiTokens;
use MongoDB\Laravel\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use MongoDB\Laravel\Relations\BelongsToMany;
use MongoDB\Laravel\Relations\HasMany;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

class User extends Authenticatable
{

    use HasFactory, Notifiable, HasAccount, HasApiTokens, UsesLandlordConnection;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(
            related: Tenant::class
        );
    }

    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(
            related: Account::class
        );
    }

    public function categories(): HasMany {
        return $this->hasMany(Category::class);
    }

    public function transactions(): HasMany {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
