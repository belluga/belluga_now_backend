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
    use DocumentModel, HasOwner, HasSlug, OwnRoles, SoftDeletes, UsesLandlordConnection;

    public const DOMAIN_TYPE_WEB = 'web';

    public const DOMAIN_TYPE_APP_ANDROID = 'app_android';

    public const DOMAIN_TYPE_APP_IOS = 'app_ios';

    public const APP_PLATFORM_ANDROID = 'android';

    public const APP_PLATFORM_IOS = 'ios';

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

    public function roleTemplates(): HasMany
    {
        return $this->hasMany(TenantRoleTemplate::class);
    }

    public function getMainDomain(): string
    {
        $primaryDomain = $this->primaryExplicitDomain();

        if ($primaryDomain) {
            return $this->formatAsHttpsDomain($primaryDomain);
        }

        if (empty($this->subdomain)) {
            throw new \RuntimeException('Tenant subdomain is not configured.');
        }

        return $this->formatAsHttpsDomain(self::defaultDomainForSubdomain($this->subdomain));
    }

    /**
     * @return array<int, string>
     */
    public function resolvedDomains(): array
    {
        $domains = $this->explicitDomains();
        $mainDomain = $this->normalizeDomainPath($this->getMainDomain());
        if ($mainDomain !== null) {
            array_unshift($domains, $mainDomain);
        }

        return array_values(array_unique($domains));
    }

    /**
     * @return array<int, string>
     */
    public function explicitDomains(): array
    {
        $fromRelation = $this->explicitDomainsFromRelation();
        if ($fromRelation !== []) {
            return $fromRelation;
        }

        return $this->explicitDomainsFromEmbeddedArray();
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domains::class);
    }

    /**
     * @return array<int, string>
     */
    public function resolvedAppDomains(): array
    {
        $typed = array_values(array_filter($this->typedAppDomainIdentifiers()));
        if ($typed !== []) {
            return array_values(array_unique($typed));
        }

        return $this->legacyAppDomains();
    }

    /**
     * @return array{android: ?string, ios: ?string}
     */
    public function typedAppDomainIdentifiers(): array
    {
        $domains = $this->domains()
            ->whereIn('type', [
                self::DOMAIN_TYPE_APP_ANDROID,
                self::DOMAIN_TYPE_APP_IOS,
            ])
            ->orderBy('created_at')
            ->get()
            ->all();

        $resolved = [
            self::APP_PLATFORM_ANDROID => null,
            self::APP_PLATFORM_IOS => null,
        ];

        foreach ($domains as $domain) {
            if (! $domain instanceof Domains) {
                continue;
            }

            $path = $this->normalizeDomainPath($domain->path);
            if ($path === null) {
                continue;
            }

            if ($domain->type === self::DOMAIN_TYPE_APP_ANDROID
                && $resolved[self::APP_PLATFORM_ANDROID] === null) {
                $resolved[self::APP_PLATFORM_ANDROID] = $path;
            }

            if ($domain->type === self::DOMAIN_TYPE_APP_IOS
                && $resolved[self::APP_PLATFORM_IOS] === null) {
                $resolved[self::APP_PLATFORM_IOS] = $path;
            }
        }

        return $resolved;
    }

    public function appDomainIdentifierForPlatform(string $platform): ?string
    {
        $normalizedPlatform = Str::lower(trim($platform));
        if (! in_array($normalizedPlatform, [self::APP_PLATFORM_ANDROID, self::APP_PLATFORM_IOS], true)) {
            return null;
        }

        return $this->typedAppDomainIdentifiers()[$normalizedPlatform];
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
     * @param  array  $domains  Array of domain strings to be added
     * @return ?string Returns an error message if the domain already exists, null on success
     *
     * @throws BulkWriteException When a duplicate domain is detected
     * @throws \Exception For other database or general errors
     */
    public function addDomains(array $domains): ?string
    {
        foreach ($domains as $domain) {
            try {
                $this->domains()->create([
                    'type' => 'web',
                    'path' => $domain,
                ]);
            } catch (BulkWriteException $e) {
                if (str_contains($e->getMessage(), 'E11000')) {
                    return 'Domain already exists.';
                }
                throw $e;
            } catch (\Exception $e) {
                throw new \Exception("Failed to add domain '{$domain}': ".$e->getMessage());
            }
        }

        return null;
    }

    public function getManifestData(): array
    {

        $landlord = Landlord::singleton();
        $main_color = $this->branding_data['theme_data_settings']['primary_seed_color']
            ?? $landlord->branding_data['theme_data_settings']['primary_seed_color']
            ?? '';

        return [
            'name' => $this->name,
            'short_name' => $this->short_name ?? $this->name,
            'description' => $this->description,
            'start_url' => '/',
            'display' => 'standalone',
            'background_color' => $main_color,
            'theme_color' => $main_color,
        ];
    }

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug')
            ->doNotGenerateSlugsOnUpdate();
    }

    public static function booted(): void
    {
        static::creating(function (Tenant $tenant) {
            if (empty($tenant->slug)) {
                $tenant->generateSlug();
            }

            $tenant->database = 'tenant_'.str_replace('-', '_', $tenant->slug);
        });

        static::created(function (Tenant $tenant) {
            $tenant->createDatabase();
        });
    }

    protected function createDatabase(): void
    {
        $this->makeCurrent();

        try {
            DB::connection(env('DB_CONNECTION_TENANT', 'mongodb'));
        } catch (\Exception $e) {
            throw new \Exception('MongoDB connection failed: '.$e->getMessage());
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
            '--force' => true,
        ]);

    }

    private function primaryExplicitDomain(): ?string
    {
        $domains = $this->explicitDomains();

        return $domains[0] ?? null;
    }

    /**
     * @return array<int, string>
     */
    private function explicitDomainsFromRelation(): array
    {
        $domains = $this->domains()
            ->where('type', self::DOMAIN_TYPE_WEB)
            ->orderBy('created_at')
            ->get()
            ->pluck('path')
            ->all();

        return $this->filterExplicitDomains($domains);
    }

    /**
     * @return array<int, string>
     */
    private function explicitDomainsFromEmbeddedArray(): array
    {
        $domains = $this->attributes['domains'] ?? $this->getRawOriginal('domains') ?? [];

        if (! is_array($domains) || $domains === []) {
            return [];
        }

        $candidates = [];
        foreach ($domains as $domain) {
            if (is_string($domain)) {
                $candidates[] = $domain;

                continue;
            }

            if (is_array($domain)) {
                $candidate = $domain['path'] ?? $domain['domain'] ?? null;
                if (is_string($candidate) && trim($candidate) !== '') {
                    $candidates[] = $candidate;
                }
            }
        }

        return $this->filterExplicitDomains($candidates);
    }

    private function formatAsHttpsDomain(string $domain): string
    {
        $normalized = Str::replace(['https://', 'http://'], '', $domain);
        $normalized = trim($normalized, '/');

        return 'https://'.$normalized;
    }

    /**
     * @param  array<int, mixed>  $domains
     * @return array<int, string>
     */
    private function filterExplicitDomains(array $domains): array
    {
        $resolved = [];

        foreach ($domains as $domain) {
            if (! is_string($domain)) {
                continue;
            }

            $normalized = $this->normalizeDomainPath($domain);
            if ($normalized === null || ! $this->isExplicitCustomDomain($normalized)) {
                continue;
            }

            $resolved[] = $normalized;
        }

        return array_values(array_unique($resolved));
    }

    private static function defaultDomainForSubdomain(string $subdomain): string
    {
        $rootHost = self::configuredRootHost();
        $prefix = Str::lower(trim($subdomain)).'.';

        return $prefix.$rootHost;
    }

    private static function configuredRootHost(): string
    {
        $configuredUrl = (string) config('app.url');
        $rootHost = parse_url($configuredUrl, PHP_URL_HOST);
        if (! is_string($rootHost) || $rootHost === '') {
            $rootHost = Str::replace(['https://', 'http://'], '', $configuredUrl);
            $rootHost = trim($rootHost, '/');
        }

        return Str::lower(trim($rootHost));
    }

    private function normalizeDomainPath(?string $domain): ?string
    {
        if (! is_string($domain)) {
            return null;
        }

        $normalized = Str::replace(['https://', 'http://'], '', $domain);
        $normalized = Str::lower(trim($normalized, '/'));

        return $normalized === '' ? null : $normalized;
    }

    private function isExplicitCustomDomain(string $domain): bool
    {
        $rootHost = self::configuredRootHost();
        if ($rootHost === '') {
            return true;
        }

        return ! Str::endsWith($domain, '.'.$rootHost);
    }

    /**
     * @return array<int, string>
     */
    private function legacyAppDomains(): array
    {
        $domains = $this->attributes['app_domains'] ?? $this->getRawOriginal('app_domains') ?? [];
        if (! is_array($domains)) {
            return [];
        }

        $normalized = [];
        foreach ($domains as $domain) {
            if (! is_string($domain)) {
                continue;
            }

            $candidate = $this->normalizeDomainPath($domain);
            if ($candidate === null) {
                continue;
            }

            $normalized[] = $candidate;
        }

        return array_values(array_unique($normalized));
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
