<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\HasAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use MongoDB\Laravel\Eloquent\Model as Authenticatable;
use Illuminate\Notifications\Notifiable;
use MongoDB\Laravel\Relations\BelongsToMany;

class User extends Authenticatable
{

    use HasFactory, Notifiable, HasAccount;

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

    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(
            related: Account::class
        );
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
