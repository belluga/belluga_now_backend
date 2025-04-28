<?php

namespace App\Models\Tenants;

use App\Models\LandlordUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class Transaction extends Model
{

    use UsesTenantConnection;
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
        return $this->belongsTo(LandlordUser::class);
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
