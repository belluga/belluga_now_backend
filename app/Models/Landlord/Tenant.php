<?php

declare(strict_types=1);

namespace App\Models\Landlord;

use App\Traits\HasOwner;
use App\Traits\OwnRoles;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Laravel\Eloquent\DocumentModel;
use MongoDB\Laravel\Eloquent\SoftDeletes;
use MongoDB\Laravel\Relations\HasMany;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;
use Spatie\Multitenancy\Models\Tenant as BaseTenant;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * @property string $name
 * @property ?string $short_name
 * @property string $slug
 * @property string $subdomain
 * @property string $database
 * @property ?string $description
 * @property array $branding_data
 * @property array $app_domains
 */
class Tenant extends BaseTenant
{
    use UsesLandlordConnection, HasSlug, DocumentModel, SoftDeletes, HasOwner, OwnRoles;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'database',
        'subdomain',
        'app_domains',
        'domains',
        'settings',
        'organization_id',
    ];

    public function roleTemplates(): HasMany {
        return $this->hasMany(TenantRoleTemplate::class);
    }

    public function getMainDomain(): string
    {
        $primaryDomain = $this->primaryDomainFromRelation()
            ?? $this->primaryDomainFromEmbeddedArray();

        if ($primaryDomain) {
            return $this->formatAsHttpsDomain($primaryDomain);
        }

        $landlordHost = Str::replace(['https://', 'http://'], '', (string) config('app.url'));

        return $this->formatAsHttpsDomain($this->subdomain . '.' . $landlordHost);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domains::class);
    }

    public static function resolve(): static
    {
        $tenant = static::current();

        if ($tenant === null) {
            abort(422, 'Tenant context not available.');
        }

        return $tenant;
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

        $landlord = Landlord::singleton();
        $main_color = $this->branding_data["theme_data_settings"]['primary_seed_color']
            ?? $landlord->branding_data["theme_data_settings"]['primary_seed_color']
            ?? '';


        return [
            'name'             => $this->name,
            'short_name'       => $this->short_name ?? $this->name,
            'description'      => $this->description,
            'start_url'        => '/',
            'display'          => 'standalone',
            'background_color' => $main_color,
            'theme_color'      => $main_color
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
        $paths = config('multitenancy.tenant_migration_paths', ['database/migrations/tenants']);

        Artisan::call('migrate', [
            '--database' => config('multitenancy.tenant_database_connection_name'),
            '--path' => $paths,
            '--force' => true
        ]);

    }

    private function primaryDomainFromRelation(): ?string
    {
        $mainDomain = $this->domains()
            ->where('main', true)
            ->orderBy('created_at')
            ->first();

        if ($mainDomain?->path) {
            return $mainDomain->path;
        }

        $firstDomain = $this->domains()
            ->orderBy('created_at')
            ->first();

        return $firstDomain?->path;
    }

    private function primaryDomainFromEmbeddedArray(): ?string
    {
        $domains = $this->getAttribute('domains') ?? [];

        if (! is_array($domains) || $domains === []) {
            return null;
        }

        foreach ($domains as $domain) {
            if (is_array($domain) && ($domain['main'] ?? false)) {
                return $domain['path'] ?? $domain['domain'] ?? null;
            }
        }

        $first = $domains[0];

        if (is_array($first)) {
            return $first['path'] ?? $first['domain'] ?? null;
        }

        return is_string($first) ? $first : null;
    }

    private function formatAsHttpsDomain(string $domain): string
    {
        $normalized = Str::replace(['https://', 'http://'], '', $domain);
        $normalized = trim($normalized, '/');

        return 'https://' . $normalized;
    }

    public function setDomainsAttribute(?array $domains): void
    {
        if ($domains === null) {
            $this->attributes['domains'] = null;

            return;
        }

        $this->attributes['domains'] = array_map(static function ($domain) {
            if (is_string($domain)) {
                return Str::lower(trim($domain));
            }

            return $domain;
        }, $domains);
    }

    protected $casts = [];
}
