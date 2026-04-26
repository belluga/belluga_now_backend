<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Landlord\Tenant;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCaseAuthenticated;

#[Group('atlas-critical')]
class ApiCorsOwnershipTest extends TestCaseAuthenticated
{
    public function test_landlord_api_returns_single_cors_header_for_get_and_preflight(): void
    {
        $origin = $this->landlordOrigin();
        $url = $this->landlordUrl('api/v1/environment');

        $getResponse = $this->withHeaders([
            'Origin' => $origin,
        ])->get($url);

        $getResponse->assertStatus(200);
        $this->assertCorsResponse($getResponse, $origin, false);

        $preflightResponse = $this->withHeaders([
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'Authorization, Content-Type, X-App-Domain',
        ])->options($url);

        $preflightResponse->assertStatus(204);
        $this->assertCorsResponse($preflightResponse, $origin, true);
    }

    public function test_tenant_public_api_returns_single_cors_header_for_get_and_preflight(): void
    {
        $tenant = $this->currentTenant();
        $origin = $this->tenantOrigin();
        $url = $this->tenantUrl($tenant, 'api/v1/environment');

        $getResponse = $this->withHeaders([
            'Origin' => $origin,
        ])->get($url);

        $getResponse->assertStatus(200);
        $this->assertCorsResponse($getResponse, $origin, false);

        $preflightResponse = $this->withHeaders([
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'Authorization, Content-Type, X-App-Domain',
        ])->options($url);

        $preflightResponse->assertStatus(204);
        $this->assertCorsResponse($preflightResponse, $origin, true);
    }

    public function test_tenant_admin_api_returns_single_cors_header_for_get_and_preflight(): void
    {
        $tenant = $this->currentTenant();
        $origin = $this->tenantOrigin();
        $url = $this->tenantUrl($tenant, 'admin/api/v1/me');

        $getResponse = $this->withHeaders(array_merge($this->getHeaders(), [
            'Origin' => $origin,
        ]))->get($url);

        $getResponse->assertStatus(200);
        $this->assertCorsResponse($getResponse, $origin, false);

        $preflightResponse = $this->withHeaders([
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'Authorization, Content-Type, X-App-Domain',
        ])->options($url);

        $preflightResponse->assertStatus(204);
        $this->assertCorsResponse($preflightResponse, $origin, true);
    }

    public function test_unlisted_origin_is_not_echoed_back(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'https://evil.example.test',
        ])->get($this->landlordUrl('api/v1/environment'));

        $response->assertStatus(200);
        $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
    }

    private function assertCorsResponse(TestResponse $response, string $origin, bool $preflight): void
    {
        $this->assertSame([$origin], $response->headers->all('Access-Control-Allow-Origin'));
        $this->assertSame(['true'], $response->headers->all('Access-Control-Allow-Credentials'));

        if ($preflight) {
            $this->assertSame(
                ['GET, POST, PUT, PATCH, DELETE, OPTIONS'],
                $response->headers->all('Access-Control-Allow-Methods')
            );
            $this->assertSame(
                [
                    'accept, authorization, cache-control, content-language, content-type, dnt, if-modified-since, origin, range, user-agent, x-app-domain, x-csrf-token, x-http-method-override, x-requested-with, x-xsrf-token',
                ],
                $response->headers->all('Access-Control-Allow-Headers')
            );
            $this->assertSame(['86400'], $response->headers->all('Access-Control-Max-Age'));
        } else {
            $this->assertTrue($response->headers->has('Access-Control-Allow-Origin'));
        }
    }

    private function landlordOrigin(): string
    {
        return $this->originForHost($this->host);
    }

    private function tenantOrigin(): string
    {
        $tenant = $this->currentTenant();

        return $this->originForHost(sprintf('%s.%s', $tenant->subdomain, $this->host));
    }

    private function landlordUrl(string $path): string
    {
        return $this->urlForHost($this->host, $path);
    }

    private function tenantUrl(Tenant $tenant, string $path): string
    {
        return $this->urlForHost(sprintf('%s.%s', $tenant->subdomain, $this->host), $path);
    }

    private function originForHost(string $host): string
    {
        $appUrlParts = parse_url((string) config('app.url')) ?: [];
        $scheme = strtolower((string) ($appUrlParts['scheme'] ?? 'http'));
        $port = isset($appUrlParts['port']) ? ':' . $appUrlParts['port'] : '';

        return sprintf('%s://%s%s', $scheme, $host, $port);
    }

    private function urlForHost(string $host, string $path): string
    {
        return sprintf('%s/%s', $this->originForHost($host), ltrim($path, '/'));
    }

    private function currentTenant(): Tenant
    {
        return Tenant::query()->firstOrFail();
    }
}
