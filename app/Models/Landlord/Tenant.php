<?php

namespace App\Models\Landlord;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use MongoDB\Laravel\Eloquent\DocumentModel;
use MongoDB\Laravel\Relations\BelongsToMany;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;
use Spatie\Multitenancy\Models\Tenant as BaseTenant;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Tenant extends BaseTenant
{
    use UsesLandlordConnection, HasSlug, DocumentModel;

    protected $fillable = [
        'name',
        'subdomain'
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(LandlordUser::class);
    }

    protected $casts = [
        'domains' => 'array',
        'app_domains' => 'array'
    ];

    /**
     * Verifica se um domínio pertence a este tenant
     *
     * @param string $domain
     * @return bool
     */
    public function hasDomain(string $domain): bool
    {
        return in_array($domain, $this->domains ?? []);
    }

    /**
     * Verifica se um domínio de app pertence a este tenant
     *
     * @param string $domain
     * @return bool
     */
    public function hasAppDomain(string $domain): bool
    {
        return in_array($domain, $this->app_domains ?? []);
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
}
