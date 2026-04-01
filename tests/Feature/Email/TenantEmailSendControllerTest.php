<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\TenantSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class TenantEmailSendControllerTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    private static bool $bootstrapped = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $tenant = Tenant::query()->where('subdomain', $this->tenant->subdomain)->firstOrFail();
        $tenant->makeCurrent();

        TenantSettings::query()->delete();
    }

    public function test_public_email_send_returns_integration_pending_when_settings_are_incomplete(): void
    {
        $response = $this->postJson("{$this->base_api_tenant}email/send", [
            'email' => 'lead@example.org',
            'whatsapp' => '(27) 99999-9999',
            'os' => 'Android',
            'app_name' => 'Guarappari',
        ]);

        $response->assertStatus(503);
        $response->assertJson([
            'ok' => false,
            'message' => 'Integracao de email pendente. Informe ao administrador do site.',
        ]);
    }

    public function test_public_email_send_dispatches_to_resend_with_tenant_settings(): void
    {
        TenantSettings::create([
            'resend_email' => [
                'token' => 're_live_token',
                'from' => 'Belluga <noreply@belluga.space>',
                'to' => ['admin@bellugasolutions.com.br'],
                'cc' => ['ops@bellugasolutions.com.br'],
                'bcc' => [],
                'reply_to' => ['reply@bellugasolutions.com.br'],
            ],
        ]);

        Http::fake([
            'https://api.resend.com/emails' => Http::response([
                'id' => 'msg_123',
            ], 200),
        ]);

        $response = $this->postJson("{$this->base_api_tenant}email/send", [
            'email' => 'lead@example.org',
            'whatsapp' => '(27) 99999-9999',
            'os' => 'Android',
            'app_name' => 'Guarappari',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
            'provider' => 'resend',
            'message_id' => 'msg_123',
        ]);

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://api.resend.com/emails') {
                return false;
            }

            return $request->hasHeader('Authorization', 'Bearer re_live_token')
                && ($request['from'] ?? null) === 'Belluga <noreply@belluga.space>'
                && ($request['to'][0] ?? null) === 'admin@bellugasolutions.com.br'
                && ($request['cc'][0] ?? null) === 'ops@bellugasolutions.com.br'
                && ($request['reply_to'][0] ?? null) === 'reply@bellugasolutions.com.br'
                && str_contains((string) ($request['subject'] ?? ''), 'Novo Testador VIP');
        });
    }

    public function test_public_email_send_returns_bad_gateway_when_resend_transport_fails(): void
    {
        TenantSettings::create([
            'resend_email' => [
                'token' => 're_live_token',
                'from' => 'Belluga <noreply@belluga.space>',
                'to' => ['admin@bellugasolutions.com.br'],
                'cc' => [],
                'bcc' => [],
                'reply_to' => [],
            ],
        ]);

        Http::fake([
            'https://api.resend.com/emails' => static function (): never {
                throw new ConnectionException('Connection refused');
            },
        ]);

        $response = $this->postJson("{$this->base_api_tenant}email/send", [
            'email' => 'lead@example.org',
            'whatsapp' => '(27) 99999-9999',
            'os' => 'Android',
            'app_name' => 'Guarappari',
        ]);

        $response->assertStatus(502);
        $response->assertJson([
            'ok' => false,
            'message' => 'Nao foi possivel enviar seu contato agora. Tente novamente em instantes.',
        ]);
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => $this->tenant->name, 'subdomain' => $this->tenant->subdomain],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#ffffff',
                'secondary_seed_color' => '#000000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ["{$this->tenant->subdomain}.test"],
        );

        $service->initialize($payload);
    }
}
