<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Landlord\Tenant;
use Tests\TestCaseAuthenticated;

class ApiUnauthenticatedJsonChallengeTest extends TestCaseAuthenticated
{
    public function test_tenant_protected_api_returns_same_json_401_with_or_without_accept_header(): void
    {
        $this->clearAuthState();

        $noAccept = $this
            ->withServerVariables($this->tenantServerVariables())
            ->get($this->tenantApiUrl('agenda?page=1&page_size=10'));

        $jsonAccept = $this
            ->withServerVariables($this->tenantServerVariables())
            ->getJson($this->tenantApiUrl('agenda?page=1&page_size=10'));

        $this->assertCanonicalJsonUnauthorizedChallenge($noAccept);
        $this->assertCanonicalJsonUnauthorizedChallenge($jsonAccept);
        $this->assertSame($jsonAccept->json(), $noAccept->json());
    }

    public function test_landlord_protected_api_returns_same_json_401_with_or_without_accept_header(): void
    {
        $this->clearAuthState();

        $noAccept = $this
            ->withServerVariables($this->landlordServerVariables())
            ->get('admin/api/v1/me');

        $jsonAccept = $this
            ->withServerVariables($this->landlordServerVariables())
            ->getJson('admin/api/v1/me');

        $this->assertCanonicalJsonUnauthorizedChallenge($noAccept);
        $this->assertCanonicalJsonUnauthorizedChallenge($jsonAccept);
        $this->assertSame($jsonAccept->json(), $noAccept->json());
    }

    public function test_anonymous_authenticated_agenda_request_remains_successful(): void
    {
        $token = $this->issueAnonymousIdentityToken();

        $response = $this
            ->withServerVariables($this->tenantServerVariables())
            ->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson($this->tenantApiUrl('agenda?page=1&page_size=10'));

        $response->assertOk();
    }

    public function test_tenant_public_shell_route_remains_html(): void
    {
        $response = $this
            ->withServerVariables($this->tenantServerVariables())
            ->get($this->tenantWebUrl('descobrir'));

        $response->assertOk();
        $this->assertSame('text/html; charset=UTF-8', (string) $response->headers->get('Content-Type'));
    }

    private function assertCanonicalJsonUnauthorizedChallenge(object $response): void
    {
        $response->assertStatus(401);
        $this->assertStringStartsWith(
            'application/json',
            (string) $response->headers->get('Content-Type')
        );
        $this->assertSame(['message' => 'Unauthenticated.'], $response->json());
        $this->assertStringNotContainsString('Route [login] not defined', $response->getContent());
    }

    /**
     * @return array<string, string>
     */
    private function tenantServerVariables(): array
    {
        $host = $this->tenantHost();

        return [
            'HTTP_HOST' => $host,
            'SERVER_NAME' => $host,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function landlordServerVariables(): array
    {
        return [
            'HTTP_HOST' => $this->host,
            'SERVER_NAME' => $this->host,
        ];
    }

    private function tenantApiUrl(string $path): string
    {
        return sprintf('http://%s/api/v1/%s', $this->tenantHost(), ltrim($path, '/'));
    }

    private function tenantWebUrl(string $path): string
    {
        return sprintf('http://%s/%s', $this->tenantHost(), ltrim($path, '/'));
    }

    private function tenantHost(): string
    {
        $tenant = $this->canonicalTenant();

        return sprintf('%s.%s', $tenant->subdomain, $this->host);
    }

    private function canonicalTenant(): Tenant
    {
        return $this->ensureCanonicalTenantExists();
    }

    private function issueAnonymousIdentityToken(): string
    {
        $response = $this
            ->withServerVariables($this->tenantServerVariables())
            ->postJson($this->tenantApiUrl('anonymous/identities'), [
                'device_name' => 'api-unauthenticated-json-challenge-test-device',
                'fingerprint' => [
                    'hash' => hash('sha256', 'api-unauthenticated-json-challenge-test-device'),
                    'user_agent' => 'ApiUnauthenticatedJsonChallengeTest/1.0',
                    'locale' => 'pt-BR',
                ],
                'metadata' => [
                    'source' => 'feature-test',
                ],
            ]);

        $response->assertStatus(201);

        $token = (string) $response->json('data.token');
        $this->assertNotSame('', trim($token));

        return $token;
    }

    private function clearAuthState(): void
    {
        auth('sanctum')->forgetUser();
        auth()->forgetGuards();
    }
}
