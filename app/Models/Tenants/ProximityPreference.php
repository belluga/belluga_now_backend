<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use Illuminate\Database\Eloquent\Model;
use MongoDB\Laravel\Eloquent\DocumentModel;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class ProximityPreference extends Model
{
    use DocumentModel;
    use UsesTenantConnection;

    protected $table = 'proximity_preferences';

    protected $fillable = [
        'owner_user_id',
        'max_distance_meters',
        'location_preference',
    ];

    protected $casts = [
        'max_distance_meters' => 'integer',
    ];
}
