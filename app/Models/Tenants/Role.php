<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use App\Models\Landlord\RoleTemplate;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Role extends Model
{
    use UsesTenantConnection, HasSlug;

    protected $casts = [
        'permissions' => 'array',
    ];

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug');
    }

    public function roleTemplate(): BelongsTo
    {
        return $this->belongsTo(RoleTemplate::class, 'template_id');
    }

    public function hasPermission(string $moduleId, string $action, string $scope = 'all'): bool
    {
        $permission = collect($this->permissions)
            ->firstWhere('module_id', $moduleId);

        if (!$permission) {
            return false;
        }

        $actionConfig = $permission['actions'][$action] ?? null;

        if (!$actionConfig) {
            return false;
        }

        if ($actionConfig['scope'] === 'all') {
            return true;
        }

        if ($actionConfig['scope'] === $scope) {
            return true;
        }

        return false;
    }
}
