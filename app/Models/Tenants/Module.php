<?php

namespace App\Models\Tenants;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\MorphMany;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class Module extends Model
{
    use HasFactory, UsesTenantConnection;

    /**
     * The collection associated with the model.
     *
     * @var string
     */
    protected $table = 'modules';

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'fields_schema' => 'array',
        'is_system' => 'boolean',
    ];

    public function owner(): MorphMany {
        return $this->morphMany(ModuleItem::class, 'owner');
    }

    /**
     * Get the items for the module.
     */
    public function items()
    {
        return $this->hasMany(ModuleItem::class);
    }
}
