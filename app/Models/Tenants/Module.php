<?php

namespace App\Models\Tenants;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class Module extends Model
{
    use HasFactory, UsesTenantConnection;

    /**
     * The collection associated with the model.
     *
     * @var string
     */
    protected $collection = 'modules';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'description',
        'slug',
        'fields_schema',
        'is_system',
        'tenant_id'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'fields_schema' => 'array',
        'is_system' => 'boolean',
    ];

    /**
     * Get the items for the module.
     */
    public function items()
    {
        return $this->hasMany(ModuleItem::class);
    }
}
