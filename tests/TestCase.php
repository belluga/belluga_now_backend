<?php

namespace Tests;

use App\Models\Landlord\Tenant;
use Belluga\Settings\Models\Landlord\LandlordSettings;
use Belluga\Settings\Models\Tenants\TenantSettings;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Api\Traits\ClearConfigCacheOnce;
use Tests\Api\Traits\MigrateFreshSeedOnce;
use Tests\Helpers\Landlord;
use Tests\Helpers\TenantLabels;

abstract class TestCase extends BaseTestCase
{
    use ClearConfigCacheOnce, MigrateFreshSeedOnce;

    protected string $prefix = 'default';

    protected string $host {
        get {
            $host = parse_url(config('app.url'), PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return $host;
            }

            return 'nginx';
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearConfigCacheOnce();
        $this->migrateOnce();
        $_SERVER['HTTP_HOST'] = $this->host;
        $_SERVER['SERVER_NAME'] = $this->host;
        $this->withServerVariables(['HTTP_HOST' => $this->host]);
    }

    protected function tearDown(): void
    {
        // RateLimiter facade mocks can leak across tests unless the bound instance is reset.
        app()->forgetInstance(CacheRateLimiter::class);
        RateLimiter::clearResolvedInstance(CacheRateLimiter::class);

        parent::tearDown();
    }

    protected function normalizeTestUri(string $uri, ?string $hostOverride = null): string
    {
        if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
            return $uri;
        }

        $host = $hostOverride;
        if (! is_string($host) || $host === '') {
            $host = $this->host;
        }

        if ($uri === '') {
            return "http://{$host}/";
        }

        if ($uri[0] !== '/') {
            $uri = "/{$uri}";
        }

        return "http://{$host}{$uri}";
    }

    public function call(
        $method,
        $uri,
        $parameters = [],
        $cookies = [],
        $files = [],
        $server = [],
        $content = null
    ) {
        $effectiveServer = array_replace($this->serverVariables, $server);
        $hostOverride = null;
        if (isset($effectiveServer['HTTP_HOST']) && is_string($effectiveServer['HTTP_HOST']) && $effectiveServer['HTTP_HOST'] !== '') {
            $hostOverride = $effectiveServer['HTTP_HOST'];
        } elseif (isset($effectiveServer['SERVER_NAME']) && is_string($effectiveServer['SERVER_NAME']) && $effectiveServer['SERVER_NAME'] !== '') {
            $hostOverride = $effectiveServer['SERVER_NAME'];
        }

        $uri = $this->normalizeTestUri($uri, $hostOverride);

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected string $api_url_admin {
        get {
            return 'admin/api/v1';
        }
    }

    protected Landlord $landlord {
        get {
            return new Landlord('landlord');
        }
    }

    protected function resolveCanonicalTenant(
        ?TenantLabels $labels = null,
        bool $allowSingleTenantContext = false
    ): Tenant {
        $labels ??= $this->landlord->tenant_primary;
        $expectedSubdomain = trim((string) $labels->subdomain);

        if ($expectedSubdomain !== '') {
            $tenantBySubdomain = Tenant::query()
                ->where('subdomain', $expectedSubdomain)
                ->first();

            if ($tenantBySubdomain instanceof Tenant) {
                return $tenantBySubdomain;
            }
        }

        $expectedSlug = '';
        try {
            $expectedSlug = trim((string) $labels->slug);
        } catch (\TypeError) {
            $expectedSlug = '';
        }
        if ($expectedSlug !== '') {
            $tenantBySlug = Tenant::query()
                ->where('slug', $expectedSlug)
                ->first();

            if ($tenantBySlug instanceof Tenant) {
                return $tenantBySlug;
            }
        }

        if ($allowSingleTenantContext) {
            $candidateTenants = Tenant::query()
                ->limit(2)
                ->get()
                ->all();

            if (count($candidateTenants) === 1 && $candidateTenants[0] instanceof Tenant) {
                return $candidateTenants[0];
            }
        }

        throw new \RuntimeException(sprintf(
            'Unable to resolve canonical tenant for test context (subdomain: %s, slug: %s).',
            $expectedSubdomain,
            $expectedSlug
        ));
    }

    protected function makeCanonicalTenantCurrent(
        ?TenantLabels $labels = null,
        bool $allowSingleTenantContext = false
    ): Tenant {
        $tenant = $this->resolveCanonicalTenant($labels, $allowSingleTenantContext);
        $tenant->makeCurrent();

        return $tenant;
    }

    /**
     * @param  array<int, string>  $enabledMethods
     * @param  array<int, string>  $availableMethods
     */
    protected function setTenantPublicAuthFixture(
        array $enabledMethods,
        array $availableMethods = ['password', 'phone_otp'],
        bool $allowTenantCustomization = true,
        ?Tenant $tenant = null,
    ): void {
        $landlordSettings = LandlordSettings::current();
        if ($landlordSettings === null) {
            $landlordSettings = new LandlordSettings;
            $landlordSettings->setAttribute('_id', 'settings_root');
        }

        $landlordSettings->setAttribute('tenant_public_auth', [
            'available_methods' => $availableMethods,
            'allow_tenant_customization' => $allowTenantCustomization,
        ]);
        $landlordSettings->save();

        $tenant ??= Tenant::current() ?? $this->resolveCanonicalTenant(allowSingleTenantContext: true);
        $tenant->makeCurrent();

        $tenantSettings = TenantSettings::current();
        if ($tenantSettings === null) {
            $tenantSettings = new TenantSettings;
            $tenantSettings->setAttribute('_id', 'settings_root');
        }

        $tenantSettings->setAttribute('tenant_public_auth', [
            'enabled_methods' => $enabledMethods,
        ]);
        $tenantSettings->save();

        $tenant->makeCurrent();
    }

    protected function getGlobal($key): mixed
    {
        global $params;

        if (! isset($params)) {
            return null;
        }

        $key_to_retrieve = "{$this->prefix}.$key";

        return array_key_exists($key_to_retrieve, $params) ? $params[$key_to_retrieve] : null;
    }

    protected function setGlobal($key, $value): void
    {
        global $params;
        $params["{$this->prefix}.$key"] = $value;
    }
}
