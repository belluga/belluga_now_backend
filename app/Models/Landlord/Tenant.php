<?php

declare(strict_types=1);

namespace App\Models\Landlord;

use App\DataObjects\Branding\BrandingData;
use App\Traits\HasOwner;
use App\Traits\OwnRoles;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Laravel\Eloquent\DocumentModel;
use MongoDB\Laravel\Eloquent\SoftDeletes;
use MongoDB\Laravel\Relations\HasMany;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;
use Spatie\Multitenancy\Models\Tenant as BaseTenant;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Tenant extends BaseTenant
{
    use UsesLandlordConnection, HasSlug, DocumentModel, SoftDeletes, HasOwner, OwnRoles;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'branding_data',
        'database',
        'subdomain',
        'app_domains',
        'domains',
    ];

    public function roleTemplates(): HasMany {
        return $this->hasMany(TenantRoleTemplate::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domains::class);
    }

    /**
     * Add multiple domains to the tenant
     *
     * @param array $domains Array of domain strings to be added
     * @return ?string Returns an error message if the domain already exists, null on success
     * @throws BulkWriteException When a duplicate domain is detected
     * @throws \Exception For other database or general errors
     */
    public function addDomains(array $domains): ?string
    {
        foreach ($domains as $domain) {
            try {
                $this->domains()->create([
                    "type" => "web",
                    "path" => $domain
                ]);
            } catch (BulkWriteException $e) {
                if (str_contains($e->getMessage(), 'E11000')) {
                    return "Domain already exists.";
                }
                throw $e;
            } catch (\Exception $e) {
                throw new \Exception("Failed to add domain '{$domain}': " . $e->getMessage());
            }
        }

        return null;
    }

    public function getManifestData(): array {

        return [
            'name'             => $this->name,
            'short_name'       => $this->slug,
            'description'      => 'Portal do ' . $this->description,
            'start_url'        => '/',
            'display'          => 'standalone',
            'background_color' => $this->primary_color,
            'theme_color'      => $this->primary_color,
            'icons' => [
                [
                    'src'   => $this->icon_192_url, // Full URL to the public asset
                    'sizes' => '192x192',
                    'type'  => 'image/png',
                ],
                [
                    'src'   => $this->icon_512_url,
                    'sizes' => '512x512',
                    'type'  => 'image/png',
                ],
            ],
        ];
    }

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug');
    }

    public static function booted(): void
    {
        static::creating(function (Tenant $tenant) {
            if (empty($tenant->slug)) {
                $tenant->generateSlug();
            }

            $tenant->database = 'tenant_' . str_replace('-', '_', $tenant->slug);
        });

        static::created(function (Tenant $tenant) {
            $tenant->createDatabase();
        });
    }

    protected function createDatabase(): void
    {
        $this->makeCurrent();

        try {
            DB::connection(env('DB_CONNECTION_TENANT', 'mongodb'),);
        } catch (\Exception $e) {
            throw new \Exception("MongoDB connection failed: " . $e->getMessage());
        }

        // Run migrations
        $this->runMigrations();

        $this->forgetCurrent();
    }

    protected function runMigrations(): void
    {
        Artisan::call('migrate', [
            '--database' => config('multitenancy.tenant_database_connection_name'),
            '--path' => 'database/migrations/tenants',
            '--force' => true
        ]);

    }

    protected $casts = [
        'branding_data' => BrandingData::class,
    ];
}
