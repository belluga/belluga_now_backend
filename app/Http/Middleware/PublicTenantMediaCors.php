<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Tenants\TenantDomainResolverService;
use App\Models\Landlord\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class PublicTenantMediaCors
{
    /**
     * @var list<string>
     */
    private const MEDIA_PREFIXES = [
        'account-profiles/',
        'account-profile-types/',
        'static-assets/',
        'event-types/',
        'static-profile-types/',
        'events/',
        'map-filters/',
        'branding-public-web/',
        'api/v1/media/',
    ];

    public function __construct(
        private readonly TenantDomainResolverService $domainResolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isPublicMediaRequest($request)) {
            return $next($request);
        }

        $origin = $this->resolveOrigin($request);
        if ($origin === null) {
            return $next($request);
        }

        $tenant = $this->resolveRequestTenant($request);
        $isAllowedTenantOrigin = $tenant instanceof Tenant
            && $this->isTenantOrigin($tenant, $origin['host']);

        if ($isAllowedTenantOrigin && $request->isMethod('OPTIONS')) {
            return $this->withCorsHeaders(response('', 204), $origin['value'], preflight: true);
        }

        $response = $next($request);
        if (! $isAllowedTenantOrigin) {
            return $this->withoutCorsHeaders($response);
        }

        return $this->withCorsHeaders($response, $origin['value'], preflight: false);
    }

    private function isPublicMediaRequest(Request $request): bool
    {
        if (! in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return false;
        }

        $path = ltrim($request->path(), '/');
        foreach (self::MEDIA_PREFIXES as $prefix) {
            if (Str::startsWith($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{value: string, host: string}|null
     */
    private function resolveOrigin(Request $request): ?array
    {
        $origin = trim((string) $request->headers->get('Origin', ''));
        if ($origin === '') {
            return null;
        }

        $scheme = strtolower((string) parse_url($origin, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = $this->normalizeHost((string) parse_url($origin, PHP_URL_HOST));
        if ($host === null) {
            return null;
        }

        return [
            'value' => $origin,
            'host' => $host,
        ];
    }

    private function resolveRequestTenant(Request $request): ?Tenant
    {
        $host = $this->normalizeHost($request->getHost());
        if ($host === null) {
            return Tenant::current();
        }

        $tenant = $this->domainResolver->findTenantByDomain($host);
        if ($tenant instanceof Tenant) {
            return $tenant;
        }

        $configuredRoot = $this->configuredRootHost();
        if ($configuredRoot !== null && Str::endsWith($host, '.'.$configuredRoot)) {
            return $this->findTenantBySubdomain($host);
        }

        return Tenant::current();
    }

    private function isTenantOrigin(Tenant $tenant, string $originHost): bool
    {
        return in_array($originHost, $this->tenantAllowedHosts($tenant), true);
    }

    /**
     * @return list<string>
     */
    private function tenantAllowedHosts(Tenant $tenant): array
    {
        $hosts = [];
        foreach ($tenant->resolvedDomains() as $domain) {
            $host = $this->normalizeHost($domain);
            if ($host !== null) {
                $hosts[] = $host;
            }
        }

        $configuredRoot = $this->configuredRootHost();
        $subdomain = strtolower(trim((string) $tenant->subdomain));
        if ($configuredRoot !== null && $subdomain !== '') {
            $hosts[] = "{$subdomain}.{$configuredRoot}";
        }

        return array_values(array_unique($hosts));
    }

    private function findTenantBySubdomain(string $host): ?Tenant
    {
        $subdomain = trim((string) Str::before($host, '.'));
        if ($subdomain === '' || in_array($subdomain, ['localhost', '127', 'nginx'], true)) {
            return null;
        }

        return Tenant::query()->where('subdomain', $subdomain)->first();
    }

    private function configuredRootHost(): ?string
    {
        $configuredUrl = trim((string) config('app.url'));
        if ($configuredUrl === '') {
            return null;
        }

        return $this->normalizeHost($configuredUrl);
    }

    private function normalizeHost(string $value): ?string
    {
        $candidate = strtolower(trim($value));
        if ($candidate === '') {
            return null;
        }

        if (! str_contains($candidate, '://')) {
            $candidate = 'https://'.$candidate;
        }

        $host = parse_url($candidate, PHP_URL_HOST);
        if (! is_string($host) || trim($host) === '') {
            return null;
        }

        return strtolower(trim($host));
    }

    private function withCorsHeaders(Response $response, string $origin, bool $preflight): Response
    {
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->setVary(array_values(array_unique([...$response->getVary(), 'Origin'])));
        $response->headers->remove('Access-Control-Allow-Credentials');

        if ($preflight) {
            $response->headers->set('Access-Control-Allow-Methods', 'GET, HEAD, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Origin, Accept, Range, Content-Type');
            $response->headers->set('Access-Control-Max-Age', '86400');
        }

        return $response;
    }

    private function withoutCorsHeaders(Response $response): Response
    {
        $response->headers->remove('Access-Control-Allow-Origin');
        $response->headers->remove('Access-Control-Allow-Credentials');
        $response->headers->remove('Access-Control-Allow-Methods');
        $response->headers->remove('Access-Control-Allow-Headers');
        $response->headers->remove('Access-Control-Max-Age');
        $response->setVary(array_values(array_filter(
            $response->getVary(),
            static fn (string $header): bool => strcasecmp($header, 'Origin') !== 0,
        )));

        return $response;
    }
}
