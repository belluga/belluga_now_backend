<?php

declare(strict_types=1);

namespace Tests\Feature\Tenants;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Application\Auth\PasswordResetTokenService;
use App\Events\Auth\PasswordResetTokenIssued;
use App\Jobs\Telemetry\DeliverTelemetryEventJob;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\TenantSettings;
use Belluga\Settings\Models\Landlord\LandlordSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class PasswordRegistrationControllerTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            Tenant::query()->firstOrFail()->update([
                'app_domains' => ['tenant-nu.test'],
            ]);
            self::$bootstrapped = true;
        }

        Tenant::query()->firstOrFail()->makeCurrent();
    }

    public function test_registers_new_identity(): void
    {
        $this->enablePasswordPublicAuth();

        $email = sprintf(
            'feature-registered-%s@example.org',
            (string) Str::uuid()
        );

        $response = $this->withHeaders(['X-App-Domain' => 'tenant-nu.test'])
            ->postJson(sprintf('http://%s.%s/api/v1/auth/register/password', 'tenant-nu', $this->host), [
                'name' => 'Feature Registered User',
                'email' => $email,
                'password' => 'Secret!234',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.identity_state', 'registered');

        $userId = $response->json('data.user_id');
        Tenant::query()->firstOrFail()->makeCurrent();
        $user = \App\Models\Tenants\AccountUser::query()->findOrFail(new ObjectId($userId));
        $this->assertSame('registered', $user->identity_state);
        $this->assertTrue(Hash::check('Secret!234', (string) $user->password));
    }

    public function test_registers_new_identity_when_tenant_context_key_was_lost(): void
    {
        $this->enablePasswordPublicAuth();

        Context::forget((string) config('multitenancy.current_tenant_context_key', 'tenantId'));

        $email = sprintf(
            'feature-context-rebound-%s@example.org',
            (string) Str::uuid()
        );

        $response = $this->withHeaders(['X-App-Domain' => 'tenant-nu.test'])
            ->postJson(sprintf('http://%s.%s/api/v1/auth/register/password', 'tenant-nu', $this->host), [
                'name' => 'Feature Context Rebound User',
                'email' => $email,
                'password' => 'Secret!234',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.identity_state', 'registered');
    }

    public function test_password_auth_routes_are_quarantined_when_password_is_not_effective(): void
    {
        $landlordSettings = LandlordSettings::current() ?? new LandlordSettings;
        $tenantSettings = TenantSettings::current() ?? new TenantSettings;
        $originalLandlordAuth = $landlordSettings->getAttribute('tenant_public_auth');
        $originalTenantAuth = $tenantSettings->getAttribute('tenant_public_auth');

        try {
            $landlordSettings->setAttribute('_id', $landlordSettings->getAttribute('_id') ?? 'settings_root');
            $landlordSettings->setAttribute('tenant_public_auth', [
                'available_methods' => ['password', 'phone_otp'],
                'allow_tenant_customization' => true,
            ]);
            $landlordSettings->save();

            $tenantSettings->setAttribute('_id', $tenantSettings->getAttribute('_id') ?? 'settings_root');
            $tenantSettings->setAttribute('tenant_public_auth', [
                'enabled_methods' => ['phone_otp'],
            ]);
            $tenantSettings->save();

            $baseUrl = sprintf('http://%s.%s/api/v1/auth', 'tenant-nu', $this->host);
            $headers = ['X-App-Domain' => 'tenant-nu.test'];

            $login = $this->withHeaders($headers)
                ->postJson($baseUrl.'/login', [
                    'email' => 'blocked-login@example.org',
                    'password' => 'Secret!234',
                    'device_name' => 'api-client',
                ]);
            $login->assertStatus(422);
            $login->assertJsonPath('errors.auth_method.0', 'Password authentication is not enabled for this tenant.');

            $register = $this->withHeaders($headers)
                ->postJson($baseUrl.'/register/password', [
                    'name' => 'Blocked Registration',
                    'email' => 'blocked-register@example.org',
                    'password' => 'Secret!234',
                ]);
            $register->assertStatus(422);
            $register->assertJsonPath('errors.auth_method.0', 'Password authentication is not enabled for this tenant.');

            $passwordToken = $this->withHeaders($headers)
                ->postJson($baseUrl.'/password_token', [
                    'email' => 'blocked-reset@example.org',
                ]);
            $passwordToken->assertStatus(422);
            $passwordToken->assertJsonPath('errors.auth_method.0', 'Password authentication is not enabled for this tenant.');

            $passwordReset = $this->withHeaders($headers)
                ->postJson($baseUrl.'/password_reset', [
                    'email' => 'blocked-reset@example.org',
                    'password' => 'Secret!234',
                    'password_confirmation' => 'Secret!234',
                    'reset_token' => 'not-used-when-password-is-disabled',
                ]);
            $passwordReset->assertStatus(422);
            $passwordReset->assertJsonPath('errors.auth_method.0', 'Password authentication is not enabled for this tenant.');
        } finally {
            $landlordSettings->setAttribute('tenant_public_auth', $originalLandlordAuth);
            $landlordSettings->save();

            $tenantSettings->setAttribute('tenant_public_auth', $originalTenantAuth);
            $tenantSettings->save();
        }
    }

    public function test_password_auth_routes_fail_closed_when_tenant_has_no_enabled_subset(): void
    {
        $landlordSettings = LandlordSettings::current() ?? new LandlordSettings;
        $tenantSettings = TenantSettings::current() ?? new TenantSettings;
        $originalLandlordAuth = $landlordSettings->getAttribute('tenant_public_auth');
        $originalTenantAuth = $tenantSettings->getAttribute('tenant_public_auth');

        try {
            $landlordSettings->setAttribute('_id', $landlordSettings->getAttribute('_id') ?? 'settings_root');
            $landlordSettings->setAttribute('tenant_public_auth', [
                'available_methods' => ['password', 'phone_otp'],
                'allow_tenant_customization' => true,
            ]);
            $landlordSettings->save();

            $tenantSettings->setAttribute('_id', $tenantSettings->getAttribute('_id') ?? 'settings_root');
            $tenantSettings->setAttribute('tenant_public_auth', [
                'enabled_methods' => [],
            ]);
            $tenantSettings->save();

            $baseUrl = sprintf('http://%s.%s/api/v1/auth', 'tenant-nu', $this->host);
            $headers = ['X-App-Domain' => 'tenant-nu.test'];

            $login = $this->withHeaders($headers)
                ->postJson($baseUrl.'/login', [
                    'email' => 'blocked-login@example.org',
                    'password' => 'Secret!234',
                    'device_name' => 'api-client',
                ]);
            $login->assertStatus(422);
            $login->assertJsonPath('errors.auth_method.0', 'Password authentication is not enabled for this tenant.');
        } finally {
            $landlordSettings->setAttribute('tenant_public_auth', $originalLandlordAuth);
            $landlordSettings->save();

            $tenantSettings->setAttribute('tenant_public_auth', $originalTenantAuth);
            $tenantSettings->save();
        }
    }

    public function test_password_auth_routes_fail_closed_when_landlord_catalog_omits_phone_otp(): void
    {
        $landlordSettings = LandlordSettings::current() ?? new LandlordSettings;
        $tenantSettings = TenantSettings::current() ?? new TenantSettings;
        $originalLandlordAuth = $landlordSettings->getAttribute('tenant_public_auth');
        $originalTenantAuth = $tenantSettings->getAttribute('tenant_public_auth');

        try {
            $landlordSettings->setAttribute('_id', $landlordSettings->getAttribute('_id') ?? 'settings_root');
            $landlordSettings->setAttribute('tenant_public_auth', [
                'available_methods' => ['password'],
                'allow_tenant_customization' => false,
            ]);
            $landlordSettings->save();

            $tenantSettings->setAttribute('_id', $tenantSettings->getAttribute('_id') ?? 'settings_root');
            $tenantSettings->setAttribute('tenant_public_auth', []);
            $tenantSettings->save();

            $baseUrl = sprintf('http://%s.%s/api/v1/auth', 'tenant-nu', $this->host);
            $headers = ['X-App-Domain' => 'tenant-nu.test'];

            $login = $this->withHeaders($headers)
                ->postJson($baseUrl.'/login', [
                    'email' => 'blocked-login@example.org',
                    'password' => 'Secret!234',
                    'device_name' => 'api-client',
                ]);
            $login->assertStatus(422);
            $login->assertJsonPath('errors.auth_method.0', 'Password authentication is not enabled for this tenant.');
        } finally {
            $landlordSettings->setAttribute('tenant_public_auth', $originalLandlordAuth);
            $landlordSettings->save();

            $tenantSettings->setAttribute('tenant_public_auth', $originalTenantAuth);
            $tenantSettings->save();
        }
    }

    public function test_password_reset_tokens_are_hashed_single_use_and_expiring(): void
    {
        $this->enablePasswordPublicAuth();

        $email = sprintf('reset-user-%s@example.org', (string) Str::uuid());
        $password = 'Secret!234';
        $headers = ['X-App-Domain' => 'tenant-nu.test'];
        $baseUrl = sprintf('http://%s.%s/api/v1/auth', 'tenant-nu', $this->host);

        $this->withHeaders($headers)
            ->postJson($baseUrl.'/register/password', [
                'name' => 'Reset User',
                'email' => $email,
                'password' => $password,
            ])->assertCreated();

        $staleLogin = $this->withHeaders($headers)
            ->postJson($baseUrl.'/login', [
                'email' => $email,
                'password' => $password,
                'device_name' => 'pre-reset-device',
            ]);
        $staleLogin->assertOk();
        $staleBearer = (string) $staleLogin->json('data.token');

        Event::fake([PasswordResetTokenIssued::class]);

        $this->withHeaders($headers)
            ->postJson($baseUrl.'/password_token', [
                'email' => $email,
            ])->assertOk();

        $user = \App\Models\Tenants\AccountUser::query()
            ->where('emails', 'all', [strtolower($email)])
            ->firstOrFail();

        $issuedToken = null;
        Event::assertDispatched(PasswordResetTokenIssued::class, static function (PasswordResetTokenIssued $event) use ($email, $user, &$issuedToken): bool {
            $issuedToken = $event->token;

            return $event->broker === PasswordResetTokenService::TENANT_USERS_BROKER
                && $event->email === strtolower($email)
                && $event->userId === (string) $user->id
                && $event->token !== '';
        });

        $resetToken = $this->app->make(PasswordResetTokenService::class)
            ->latestIssuedTokenForTesting(
                userId: $user->id,
                broker: PasswordResetTokenService::TENANT_USERS_BROKER,
                scope: (string) Tenant::current()?->getKey(),
            );

        $record = DB::connection('landlord')
            ->table('password_reset_tokens')
            ->where('user_id', $user->id)
            ->first();

        $this->assertNotNull($record);
        $this->assertIsString($issuedToken);
        $this->assertSame($issuedToken, $resetToken);
        $this->assertIsString($resetToken);
        $this->assertNotSame('', $resetToken);
        $this->assertFalse(property_exists($record, 'token'));
        $this->assertNotNull($record->expires_at ?? null);
        $this->assertTrue(Hash::check($resetToken, (string) $record->token_hash));
        $this->assertNotSame($resetToken, (string) ($record->token_lookup_hash ?? ''));

        $newPassword = 'Reset!456';
        $this->withHeaders($headers)
            ->postJson($baseUrl.'/password_reset', [
                'email' => $email,
                'password' => $newPassword,
                'password_confirmation' => $newPassword,
                'reset_token' => $resetToken,
            ])->assertOk();

        $this->withHeaders($headers)
            ->postJson($baseUrl.'/password_reset', [
                'email' => $email,
                'password' => 'Another!789',
                'password_confirmation' => 'Another!789',
                'reset_token' => $resetToken,
            ])->assertStatus(422);

        $this->withHeaders($headers)
            ->getJson($baseUrl.'/token_validate', [
                'Authorization' => "Bearer {$staleBearer}",
            ])->assertStatus(401);

        $loginResponse = $this->withHeaders($headers)
            ->postJson($baseUrl.'/login', [
                'email' => $email,
                'password' => $newPassword,
                'device_name' => 'api-client',
            ]);
        $loginResponse->assertOk();
        $loginResponse->assertHeader('X-Api-Security-Domain', 'tenant_public_password_login');

        $this->assertNull(
            DB::connection('landlord')
                ->table('password_reset_tokens')
                ->where('user_id', $user->id)
                ->first()
        );
    }

    public function test_password_reset_rejects_passwords_below_the_canonical_minimum_without_consuming_the_token(): void
    {
        $this->enablePasswordPublicAuth();

        $email = sprintf('reset-policy-%s@example.org', (string) Str::uuid());
        $headers = ['X-App-Domain' => 'tenant-nu.test'];
        $baseUrl = sprintf('http://%s.%s/api/v1/auth', 'tenant-nu', $this->host);

        $this->withHeaders($headers)
            ->postJson($baseUrl.'/register/password', [
                'name' => 'Reset Policy User',
                'email' => $email,
                'password' => 'Secret!234',
            ])->assertCreated();

        $this->withHeaders($headers)
            ->postJson($baseUrl.'/password_token', [
                'email' => $email,
            ])->assertOk();

        $user = \App\Models\Tenants\AccountUser::query()
            ->where('emails', 'all', [strtolower($email)])
            ->firstOrFail();

        $resetToken = $this->app->make(PasswordResetTokenService::class)
            ->latestIssuedTokenForTesting(
                userId: $user->id,
                broker: PasswordResetTokenService::TENANT_USERS_BROKER,
                scope: (string) Tenant::current()?->getKey(),
            );

        $invalid = $this->withHeaders($headers)
            ->postJson($baseUrl.'/password_reset', [
                'email' => $email,
                'password' => 'Short7!',
                'password_confirmation' => 'Short7!',
                'reset_token' => $resetToken,
            ]);
        $invalid->assertStatus(422);
        $invalid->assertHeader('X-Api-Security-Domain', 'tenant_public_password_reset');
        $invalid->assertJsonValidationErrors(['password']);

        $retry = $this->withHeaders($headers)
            ->postJson($baseUrl.'/password_reset', [
                'email' => $email,
                'password' => 'Recovered!234',
                'password_confirmation' => 'Recovered!234',
                'reset_token' => $resetToken,
            ]);
        $retry->assertOk();
        $retry->assertHeader('X-Api-Security-Domain', 'tenant_public_password_reset');
    }

    public function test_password_reset_missing_user_and_wrong_token_share_the_same_invalid_response_contract(): void
    {
        $this->enablePasswordPublicAuth();

        $email = sprintf('reset-contract-%s@example.org', (string) Str::uuid());
        $headers = ['X-App-Domain' => 'tenant-nu.test'];
        $baseUrl = sprintf('http://%s.%s/api/v1/auth', 'tenant-nu', $this->host);

        $this->withHeaders($headers)
            ->postJson($baseUrl.'/register/password', [
                'name' => 'Reset Contract User',
                'email' => $email,
                'password' => 'Secret!234',
            ])->assertCreated();

        $wrongToken = $this->withHeaders($headers)
            ->postJson($baseUrl.'/password_reset', [
                'email' => $email,
                'password' => 'Recovered!234',
                'password_confirmation' => 'Recovered!234',
                'reset_token' => 'invalid-token',
            ]);

        $missingUser = $this->withHeaders($headers)
            ->postJson($baseUrl.'/password_reset', [
                'email' => 'missing-reset-user@example.org',
                'password' => 'Recovered!234',
                'password_confirmation' => 'Recovered!234',
                'reset_token' => 'invalid-token',
            ]);

        $wrongToken->assertStatus(422);
        $wrongToken->assertHeader('X-Api-Security-Domain', 'tenant_public_password_reset');
        $wrongToken->assertJsonValidationErrors(['reset_token']);

        $missingUser->assertStatus(422);
        $missingUser->assertHeader('X-Api-Security-Domain', 'tenant_public_password_reset');
        $missingUser->assertJsonValidationErrors(['reset_token']);

        $this->assertSame($wrongToken->json(), $missingUser->json());
    }

    public function test_password_registration_rejects_common_breached_passwords(): void
    {
        $this->enablePasswordPublicAuth();

        $response = $this->withHeaders(['X-App-Domain' => 'tenant-nu.test'])
            ->postJson(sprintf('http://%s.%s/api/v1/auth/register/password', 'tenant-nu', $this->host), [
                'name' => 'Common Password User',
                'email' => sprintf('common-password-%s@example.org', (string) Str::uuid()),
                'password' => 'Password123!',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
    }

    public function test_password_reset_rejects_common_breached_passwords_without_consuming_the_token(): void
    {
        $this->enablePasswordPublicAuth();

        $email = sprintf('reset-common-%s@example.org', (string) Str::uuid());
        $headers = ['X-App-Domain' => 'tenant-nu.test'];
        $baseUrl = sprintf('http://%s.%s/api/v1/auth', 'tenant-nu', $this->host);

        $this->withHeaders($headers)
            ->postJson($baseUrl.'/register/password', [
                'name' => 'Reset Common Password User',
                'email' => $email,
                'password' => 'Secret!234',
            ])->assertCreated();

        $this->withHeaders($headers)
            ->postJson($baseUrl.'/password_token', [
                'email' => $email,
            ])->assertOk();

        $user = \App\Models\Tenants\AccountUser::query()
            ->where('emails', 'all', [strtolower($email)])
            ->firstOrFail();

        $resetToken = $this->app->make(PasswordResetTokenService::class)
            ->latestIssuedTokenForTesting(
                userId: $user->id,
                broker: PasswordResetTokenService::TENANT_USERS_BROKER,
                scope: (string) Tenant::current()?->getKey(),
            );

        $invalid = $this->withHeaders($headers)
            ->postJson($baseUrl.'/password_reset', [
                'email' => $email,
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'reset_token' => $resetToken,
            ]);

        $invalid->assertStatus(422);
        $invalid->assertJsonValidationErrors(['password']);

        $retry = $this->withHeaders($headers)
            ->postJson($baseUrl.'/password_reset', [
                'email' => $email,
                'password' => 'Recovered!234',
                'password_confirmation' => 'Recovered!234',
                'reset_token' => $resetToken,
            ]);

        $retry->assertOk();
    }

    public function test_live_public_password_routes_expose_expected_security_domains(): void
    {
        $this->enablePasswordPublicAuth();

        $baseUrl = sprintf('http://%s.%s/api/v1/auth', 'tenant-nu', $this->host);
        $headers = ['X-App-Domain' => 'tenant-nu.test'];

        $register = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.51'])
            ->withHeaders($headers)
            ->postJson($baseUrl.'/register/password', [
                'name' => 'Security Domain User',
                'email' => sprintf('domain-user-%s@example.org', (string) Str::uuid()),
                'password' => 'Secret!234',
            ]);
        $register->assertHeader('X-Api-Security-Domain', 'tenant_public_password_register');

        $login = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.52'])
            ->withHeaders($headers)
            ->postJson($baseUrl.'/login', [
                'email' => 'domain-login@example.org',
                'password' => 'Secret!234',
                'device_name' => 'api-client',
            ]);
        $login->assertHeader('X-Api-Security-Domain', 'tenant_public_password_login');

        $passwordToken = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.53'])
            ->withHeaders($headers)
            ->postJson($baseUrl.'/password_token', [
                'email' => sprintf('domain-reset-%s@example.org', (string) Str::uuid()),
            ]);
        $passwordToken->assertHeader('X-Api-Security-Domain', 'tenant_public_password_reset_token');

        $passwordReset = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.54'])
            ->withHeaders($headers)
            ->postJson($baseUrl.'/password_reset', [
                'email' => sprintf('domain-reset-miss-%s@example.org', (string) Str::uuid()),
                'password' => 'Secret!234',
                'password_confirmation' => 'Secret!234',
                'reset_token' => 'not-a-valid-token',
        ]);
        $passwordReset->assertHeader('X-Api-Security-Domain', 'tenant_public_password_reset');
    }

    public function test_public_password_login_fails_closed_when_rate_limiter_backend_errors(): void
    {
        $this->enablePasswordPublicAuth();

        RateLimiter::shouldReceive('tooManyAttempts')
            ->once()
            ->andThrow(new RuntimeException('rate limiter backend unavailable'));

        $response = $this->withHeaders(['X-App-Domain' => 'tenant-nu.test'])
            ->postJson(sprintf('http://%s.%s/api/v1/auth/login', 'tenant-nu', $this->host), [
                'email' => 'subject@example.org',
                'password' => 'Secret!234',
                'device_name' => 'api-client',
            ]);

        $response->assertStatus(503);
        $response->assertJsonPath('code', 'rate_limit_unavailable');
        $response->assertHeader('X-Api-Security-Domain', 'tenant_public_password_login');
    }

    public function test_public_password_login_rate_limit_tracks_the_email_subject_across_ips(): void
    {
        $this->enablePasswordPublicAuth();

        $overrides = array_map(function (array $override): array {
            return match ((string) ($override['domain'] ?? '')) {
                'tenant_public_password_login' => [
                    ...$override,
                    'requests_per_minute' => 50,
                    'subject_requests_per_minute' => 1,
                ],
                default => $override,
            };
        }, (array) config('api_security.route_overrides', []));

        config()->set('api_security.route_overrides', $overrides);
        config()->set('api_security.lifecycle.enabled', false);
        config()->set('api_security.levels.L2.requests_per_minute', 9999);
        Cache::flush();

        $baseUrl = sprintf('http://%s.%s/api/v1/auth/login', 'tenant-nu', $this->host);
        $headers = ['X-App-Domain' => 'tenant-nu.test'];

        $first = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.91'])
            ->withHeaders($headers)
            ->postJson($baseUrl, [
                'email' => 'shared-login@example.org',
                'password' => 'Secret!234',
                'device_name' => 'api-client',
            ]);
        $first->assertStatus(403);
        $first->assertHeader('X-Api-Security-Domain', 'tenant_public_password_login');

        $second = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.92'])
            ->withHeaders($headers)
            ->postJson($baseUrl, [
                'email' => 'SHARED-LOGIN@example.org',
                'password' => 'Secret!234',
                'device_name' => 'api-client',
            ]);
        $second->assertStatus(429);
        $second->assertHeader('X-Api-Security-Domain', 'tenant_public_password_login');

        $control = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.93'])
            ->withHeaders($headers)
            ->postJson($baseUrl, [
                'email' => 'different-login@example.org',
                'password' => 'Secret!234',
                'device_name' => 'api-client',
            ]);
        $control->assertStatus(403);
        $control->assertHeader('X-Api-Security-Domain', 'tenant_public_password_login');
    }

    public function test_live_public_password_route_rate_limits_are_scoped_by_security_domain(): void
    {
        $this->enablePasswordPublicAuth();

        $overrides = array_map(function (array $override): array {
            return match ((string) ($override['domain'] ?? '')) {
                'tenant_public_password_login' => [...$override, 'requests_per_minute' => 1],
                'tenant_public_password_reset_token' => [...$override, 'requests_per_minute' => 3],
                default => $override,
            };
        }, (array) config('api_security.route_overrides', []));

        config()->set('api_security.route_overrides', $overrides);
        config()->set('api_security.lifecycle.enabled', false);
        config()->set('api_security.levels.L2.requests_per_minute', 9999);
        Cache::flush();

        $baseUrl = sprintf('http://%s.%s/api/v1/auth', 'tenant-nu', $this->host);
        $headers = ['X-App-Domain' => 'tenant-nu.test'];
        $server = ['REMOTE_ADDR' => '127.0.0.61'];

        $firstLogin = $this->withServerVariables($server)
            ->withHeaders($headers)
            ->postJson($baseUrl.'/login', [
                'email' => 'rate-limit-login@example.org',
                'password' => 'Secret!234',
                'device_name' => 'api-client',
            ]);
        $firstLogin->assertHeader('X-Api-Security-Domain', 'tenant_public_password_login');

        $secondLogin = $this->withServerVariables($server)
            ->withHeaders($headers)
            ->postJson($baseUrl.'/login', [
                'email' => 'rate-limit-login@example.org',
                'password' => 'Secret!234',
                'device_name' => 'api-client',
            ]);
        $secondLogin->assertStatus(429);

        foreach ([1, 2, 3] as $attempt) {
            $response = $this->withServerVariables($server)
                ->withHeaders($headers)
                ->postJson($baseUrl.'/password_token', [
                    'email' => sprintf('rate-limit-token-%d-%s@example.org', $attempt, (string) Str::uuid()),
                ]);

            $response->assertHeader('X-Api-Security-Domain', 'tenant_public_password_reset_token');
            $response->assertStatus(200);
        }

        $rateLimitedToken = $this->withServerVariables($server)
            ->withHeaders($headers)
            ->postJson($baseUrl.'/password_token', [
                'email' => sprintf('rate-limit-token-4-%s@example.org', (string) Str::uuid()),
            ]);
        $rateLimitedToken->assertStatus(429);
        $rateLimitedToken->assertHeader('X-Api-Security-Domain', 'tenant_public_password_reset_token');
    }

    public function test_password_token_requests_emit_generic_pre_auth_telemetry(): void
    {
        $this->enablePasswordPublicAuth();
        $this->configurePasswordResetTelemetry();
        Queue::fake();

        $response = $this->withHeaders(['X-App-Domain' => 'tenant-nu.test'])
            ->postJson(sprintf('http://%s.%s/api/v1/auth/password_token', 'tenant-nu', $this->host), [
                'email' => sprintf('missing-%s@example.org', (string) Str::uuid()),
            ]);

        $response->assertOk();

        Queue::assertPushed(
            DeliverTelemetryEventJob::class,
            function (DeliverTelemetryEventJob $job): bool {
                $envelope = $this->telemetryEnvelope($job);

                return ($envelope['event'] ?? null) === 'auth_password_token_generated'
                    && ($envelope['actor']['type'] ?? null) === 'pre_auth'
                    && ! array_key_exists('user_id', $envelope['metadata'] ?? []);
                    }
        );
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Nu', 'subdomain' => 'tenant-nu'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-nu.test']
        );

        $service->initialize($payload);
    }

    private function enablePasswordPublicAuth(): void
    {
        $landlordSettings = LandlordSettings::current() ?? new LandlordSettings;
        $tenantSettings = TenantSettings::current() ?? new TenantSettings;

        $landlordSettings->setAttribute('_id', $landlordSettings->getAttribute('_id') ?? 'settings_root');
        $landlordSettings->setAttribute('tenant_public_auth', [
            'available_methods' => ['password', 'phone_otp'],
            'allow_tenant_customization' => true,
        ]);
        $landlordSettings->save();

        $tenantSettings->setAttribute('_id', $tenantSettings->getAttribute('_id') ?? 'settings_root');
        $tenantSettings->setAttribute('tenant_public_auth', [
            'enabled_methods' => ['password'],
        ]);
        $tenantSettings->save();
    }

    private function configurePasswordResetTelemetry(): void
    {
        $tenantSettings = TenantSettings::current() ?? new TenantSettings;
        $tenantSettings->setAttribute('_id', $tenantSettings->getAttribute('_id') ?? 'settings_root');
        $tenantSettings->setAttribute('telemetry', [
            'location_freshness_minutes' => 5,
            'trackers' => [
                [
                    'type' => 'webhook',
                    'url' => 'https://telemetry.example/ingest',
                    'track_all' => true,
                ],
            ],
        ]);
        $tenantSettings->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function telemetryEnvelope(DeliverTelemetryEventJob $job): array
    {
        $property = (new \ReflectionClass($job))->getProperty('envelope');
        $property->setAccessible(true);

        /** @var array<string, mixed> $envelope */
        $envelope = $property->getValue($job);

        return $envelope;
    }
}
