<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;
use MongoDB\Laravel\Relations\HasMany;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;
use Illuminate\Database\Eloquent\Builder;

class Category extends Model
{
    use HasFactory, HasSlug;

    protected $fillable = [
        'user_id',
        'name',
    ];

    public function user(): BelongsTo {
        return $this->belongsTo(Account::class);
    }

    public function transactions(): HasMany {
        return $this->hasMany(Transaction::class);
    }

    public function getSlugOptions() : SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug');
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
