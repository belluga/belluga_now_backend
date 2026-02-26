<?php

declare(strict_types=1);

namespace Belluga\Settings\Models;

use MongoDB\Laravel\Eloquent\Model;

abstract class SettingsDocument extends Model
{
    public const ROOT_ID = 'settings_root';

    protected $table = 'settings';

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (! $model->getAttribute('_id')) {
                $model->setAttribute('_id', self::ROOT_ID);
            }
        });
    }

    public static function current(): ?self
    {
        $root = static::query()->where('_id', self::ROOT_ID)->first();

        if ($root) {
            return $root;
        }

        // Transitional fallback while old records are migrated.
        return static::query()->first();
    }
}
