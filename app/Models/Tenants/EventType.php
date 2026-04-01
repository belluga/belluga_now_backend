<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class EventType extends Model
{
    use UsesTenantConnection;

    protected $table = 'event_types';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'icon',
        'color',
        'icon_color',
    ];
}
