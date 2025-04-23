<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use MongoDB\Laravel\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use MongoDB\Laravel\Relations\BelongsTo;

class Transaction extends Model
{
    protected $fillable = [
        'category_id',
        'user_id',
        'transaction_date',
        'amount',
        'description',
    ];

    protected function casts() {
        return [
            'transaction_date' => 'date',
        ];
    }

    protected function amount(): Attribute {
        return Attribute::make(
            get: fn($value) => $value / 100,
            set: fn($value) => $value * 100,
        );
    }

    public function user(): BelongsTo {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo {
        return $this->belongsTo(Category::class);
    }

    protected static function booted(): void
    {
        if (auth()->check()) {
            static::addGlobalScope('by_user', function (Builder $builder) {
                $builder->where('user_id', (string) auth()->user()?->id);
            });
        }
    }
}
