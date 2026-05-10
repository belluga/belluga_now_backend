<?php

namespace Tests\Api\v1\Admin;

use App\Application\Auth\PasswordResetTokenService;
use App\Events\Auth\PasswordResetTokenIssued;
use App\Models\Landlord\PersonalAccessToken;
use App\Models\Landlord\LandlordUser;
use App\Support\Helpers\PhoneNumberParser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Tests\Api\Traits\AdminAuthFunctions;
use Tests\Api\Traits\AdminProfileFunctions;
use Tests\TestCaseAuthenticated;

class ApiV1AdminProfileTest extends TestCaseAuthenticated
{
    use AdminAuthFunctions, AdminProfileFunctions;

    protected string $base_api_url {
        get{
            return 'admin/api/v1/';
        }
    }

    private string $temporary_email_1 = 'temporaryemail1@gmail.com';

    private string $temporary_email_2 = 'temporaryemail2@gmail.com';

    private string $temporary_phone_1 = '5531996419823';

    private string $temporary_phone_2 = '27996419823';

    public function test_token_generate(): void
    {
        Event::fake([PasswordResetTokenIssued::class]);

        $firstResetToken = $this->issuePasswordResetTokenForCrossAdmin();
        Cache::flush();
        $resetToken = $this->issuePasswordResetTokenForCrossAdmin();

        $this->assertNotSame('', $firstResetToken);
        $this->assertNotSame('', $resetToken);
        $this->assertNotSame($firstResetToken, $resetToken);
        $this->assertSame($resetToken, $this->landlord->user_cross_tenant_admin->password_reset_token);

        Event::assertDispatched(PasswordResetTokenIssued::class, function (PasswordResetTokenIssued $event) use ($firstResetToken): bool {
            return $event->broker === PasswordResetTokenService::LANDLORD_USERS_BROKER
                && $event->email === strtolower($this->landlord->user_cross_tenant_admin->email_1)
                && $event->userId === (string) $this->landlord->user_cross_tenant_admin->user_id
                && $event->token === $firstResetToken;
        });
        Event::assertDispatched(PasswordResetTokenIssued::class, function (PasswordResetTokenIssued $event) use ($resetToken): bool {
            return $event->broker === PasswordResetTokenService::LANDLORD_USERS_BROKER
                && $event->email === strtolower($this->landlord->user_cross_tenant_admin->email_1)
                && $event->userId === (string) $this->landlord->user_cross_tenant_admin->user_id
                && $event->token === $resetToken;
        });

        $staleResponse = $this->resetPassword(
            email: $this->landlord->user_cross_tenant_admin->email_1,
            password: 'Superseded!234',
            password_confirmation: 'Superseded!234',
            reset_token: $firstResetToken,
        );
        $staleResponse->assertStatus(422);
        $staleResponse->assertHeader('X-Api-Security-Domain', 'landlord_public_password_reset');

        $this->landlord->user_cross_tenant_admin->password = 'LiveReset!234';
        $liveResponse = $this->resetPassword(
            email: $this->landlord->user_cross_tenant_admin->email_1,
            password: $this->landlord->user_cross_tenant_admin->password,
            password_confirmation: $this->landlord->user_cross_tenant_admin->password,
            reset_token: $resetToken,
        );
        $liveResponse->assertOk();
        $liveResponse->assertHeader('X-Api-Security-Domain', 'landlord_public_password_reset');
    }

    public function test_public_password_login_fails_closed_when_rate_limiter_backend_errors(): void
    {
        RateLimiter::shouldReceive('tooManyAttempts')
            ->once()
            ->andThrow(new RuntimeException('rate limiter backend unavailable'));

        $response = $this->json(
            method: 'post',
            uri: 'admin/api/v1/auth/login',
            data: [
                'email' => $this->landlord->user_cross_tenant_admin->email_1,
                'password' => 'Secret!234',
                'device_name' => 'api-client',
            ],
            headers: ['Content-Type' => 'application/json'],
        );

        $response->assertStatus(503);
        $response->assertJsonPath('code', 'rate_limit_unavailable');
        $response->assertHeader('X-Api-Security-Domain', 'landlord_public_password_login');
    }

    public function test_public_password_login_rate_limit_tracks_the_email_subject_across_ips(): void
    {
        $sharedEmail = 'shared-admin-login@example.org';

        $overrides = array_map(function (array $override): array {
            return match ((string) ($override['domain'] ?? '')) {
                'landlord_public_password_login' => [
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

        $first = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.101'])->json(
            method: 'post',
            uri: 'admin/api/v1/auth/login',
            data: [
                'email' => $sharedEmail,
                'password' => 'wrong-password',
                'device_name' => 'api-client',
            ],
            headers: ['Content-Type' => 'application/json'],
        );
        $first->assertStatus(403);
        $first->assertHeader('X-Api-Security-Domain', 'landlord_public_password_login');

        $second = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.102'])->json(
            method: 'post',
            uri: 'admin/api/v1/auth/login',
            data: [
                'email' => strtoupper($sharedEmail),
                'password' => 'wrong-password',
                'device_name' => 'api-client',
            ],
            headers: ['Content-Type' => 'application/json'],
        );
        $second->assertStatus(429);
        $second->assertHeader('X-Api-Security-Domain', 'landlord_public_password_login');

        $control = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.103'])->json(
            method: 'post',
            uri: 'admin/api/v1/auth/login',
            data: [
                'email' => 'different-admin@example.org',
                'password' => 'wrong-password',
                'device_name' => 'api-client',
            ],
            headers: ['Content-Type' => 'application/json'],
        );
        $control->assertStatus(403);
        $control->assertHeader('X-Api-Security-Domain', 'landlord_public_password_login');
    }

    public function test_reset_password_token_invalid(): void
    {

        $this->landlord->user_cross_tenant_admin->password = fake()->password(8);

        $response = $this->resetPassword(
            email: $this->landlord->user_cross_tenant_admin->email_1,
            password: $this->landlord->user_cross_tenant_admin->password,
            password_confirmation: $this->landlord->user_cross_tenant_admin->password,
            reset_token: '123456',
        );
        $response->assertStatus(422);
    }

    public function test_reset_password_rejects_passwords_below_the_canonical_minimum_without_consuming_the_token(): void
    {
        $token = $this->issuePasswordResetTokenForCrossAdmin();

        $response = $this->resetPassword(
            email: $this->landlord->user_cross_tenant_admin->email_1,
            password: 'Short7!',
            password_confirmation: 'Short7!',
            reset_token: $token,
        );
        $response->assertStatus(422);
        $response->assertHeader('X-Api-Security-Domain', 'landlord_public_password_reset');
        $response->assertJsonValidationErrors(['password']);

        $this->landlord->user_cross_tenant_admin->password = 'Recovered!234';
        $retry = $this->resetPassword(
            email: $this->landlord->user_cross_tenant_admin->email_1,
            password: $this->landlord->user_cross_tenant_admin->password,
            password_confirmation: $this->landlord->user_cross_tenant_admin->password,
            reset_token: $token,
        );
        $retry->assertOk();
    }

    public function test_reset_password_rejects_common_breached_passwords_without_consuming_the_token(): void
    {
        $token = $this->issuePasswordResetTokenForCrossAdmin();

        $response = $this->resetPassword(
            email: $this->landlord->user_cross_tenant_admin->email_1,
            password: 'Password123!',
            password_confirmation: 'Password123!',
            reset_token: $token,
        );
        $response->assertStatus(422);
        $response->assertHeader('X-Api-Security-Domain', 'landlord_public_password_reset');
        $response->assertJsonValidationErrors(['password']);

        $this->landlord->user_cross_tenant_admin->password = 'Recovered!234';
        $retry = $this->resetPassword(
            email: $this->landlord->user_cross_tenant_admin->email_1,
            password: $this->landlord->user_cross_tenant_admin->password,
            password_confirmation: $this->landlord->user_cross_tenant_admin->password,
            reset_token: $token,
        );
        $retry->assertOk();
    }

    public function test_token_reset_password_success(): void
    {
        $this->issuePasswordResetTokenForCrossAdmin();
        $this->adminLogin($this->landlord->user_cross_tenant_admin)->assertOk();
        $staleBearer = $this->landlord->user_cross_tenant_admin->token;
        $this->profileAddEmails(
            $this->landlord->user_cross_tenant_admin,
            $this->temporary_email_2,
        )->assertStatus(200);

        $this->landlord->user_cross_tenant_admin->password = fake()->password(8);

        $response = $this->resetPassword(
            email: $this->landlord->user_cross_tenant_admin->email_1,
            password: $this->landlord->user_cross_tenant_admin->password,
            password_confirmation: $this->landlord->user_cross_tenant_admin->password,
            reset_token: $this->landlord->user_cross_tenant_admin->password_reset_token
        );
        $response->assertOk();
        $response->assertHeader('X-Api-Security-Domain', 'landlord_public_password_reset');
        $this->assertNull(PersonalAccessToken::findToken($staleBearer));
        app('auth')->forgetGuards();

        $this->json(
            method: 'get',
            uri: 'admin/api/v1/auth/token_validate',
            headers: [
                'Authorization' => "Bearer {$staleBearer}",
                'Content-Type' => 'application/json',
            ]
        )->assertStatus(401);

        $response = $this->adminLogin($this->landlord->user_cross_tenant_admin);
        $response->assertOk();
        $response->assertHeader('X-Api-Security-Domain', 'landlord_public_password_login');
        $this->assertPasswordStateSynchronized(
            $this->landlord->user_cross_tenant_admin->user_id,
            $this->landlord->user_cross_tenant_admin->password
        );
        $this->assertNull(
            DB::connection('landlord')->table('password_reset_tokens')
                ->where('user_id', $this->landlord->user_cross_tenant_admin->user_id)
                ->first()
        );

        $this->landlord->user_cross_tenant_admin->token = $response->json()['data']['token'];

    }

    public function test_update_password(): void
    {
        $this->adminLogin($this->landlord->user_cross_tenant_admin)->assertOk();
        $this->profileAddEmails(
            $this->landlord->user_cross_tenant_admin,
            $this->temporary_email_2,
        )->assertStatus(200);

        $this->landlord->user_cross_tenant_admin->password = fake()->password(8);

        $response = $this->passwordUpdate(
            user: $this->landlord->user_cross_tenant_admin,
            password: $this->landlord->user_cross_tenant_admin->password,
            password_confirmation: $this->landlord->user_cross_tenant_admin->password
        );
        $response->assertOk();

        $this->adminLogout($this->landlord->user_cross_tenant_admin);

        $response = $this->adminLogin($this->landlord->user_cross_tenant_admin);
        $response->assertOk();
        $this->assertPasswordStateSynchronized(
            $this->landlord->user_cross_tenant_admin->user_id,
            $this->landlord->user_cross_tenant_admin->password
        );

    }

    public function test_login_users(): void
    {
        $response = $this->adminLogin($this->landlord->user_cross_tenant_admin);
        $response->assertOk();
        $response->assertHeader('X-Api-Security-Domain', 'landlord_public_password_login');

        $response = $this->adminLogin($this->landlord->user_cross_tenant_visitor);
        $response->assertOk();
        $response->assertHeader('X-Api-Security-Domain', 'landlord_public_password_login');
    }

    public function test_update_profile(): void
    {

        $this->landlord->user_cross_tenant_admin->name = fake()->name().' Name Created Updating Profile';

        $response = $this->profileUpdate(
            user: $this->landlord->user_cross_tenant_admin,
            data: [
                'name' => $this->landlord->user_cross_tenant_admin->name,
            ]
        );
        $response->assertOk();

        $this->assertEquals($this->landlord->user_cross_tenant_admin->name, $response->json()['name']);

    }

    public function test_add_emails(): void
    {

        $firstResponse = $this->profileAddEmails(
            $this->landlord->user_cross_tenant_admin,
            $this->temporary_email_1,
        );

        $firstResponse->assertStatus(200);
        $this->assertContains($this->temporary_email_1, $firstResponse->json()['data']['emails']);

        $secondResponse = $this->profileAddEmails(
            $this->landlord->user_cross_tenant_admin,
            $this->temporary_email_2,
        );

        $secondResponse->assertStatus(200);
        $this->assertContains($this->temporary_email_2, $secondResponse->json()['data']['emails']);
    }

    public function test_add_emails_repeated(): void
    {
        $seedDuplicate = $this->profileAddEmails(
            $this->landlord->user_cross_tenant_admin,
            $this->temporary_email_1,
        );
        $seedDuplicate->assertStatus(200);

        $userUpdate = $this->profileAddEmails(
            $this->landlord->user_cross_tenant_visitor,
            $this->temporary_email_1,
        );

        $userUpdate->assertStatus(422);

        $userUpdate->assertJsonStructure([
            'errors' => [
                'email',
            ],
        ]);
    }

    public function test_remove_email(): void
    {
        $this->profileAddEmails(
            $this->landlord->user_cross_tenant_admin,
            $this->temporary_email_1,
        )->assertStatus(200);

        $this->profileAddEmails(
            $this->landlord->user_cross_tenant_admin,
            $this->temporary_email_2,
        )->assertStatus(200);

        $userUpdate = $this->profileRemoveEmail(
            $this->landlord->user_cross_tenant_admin,
            $this->temporary_email_1,
        );
        $userUpdate->assertStatus(200);

        $this->assertNotContains($this->temporary_email_1, $userUpdate->json()['data']['emails']);

        $userUpdate = $this->profileRemoveEmail(
            $this->landlord->user_cross_tenant_admin,
            $this->temporary_email_2,
        );
        $userUpdate->assertStatus(200);

        $this->assertNotContains($this->temporary_email_2, $userUpdate->json()['data']['emails']);
    }

    public function test_add_phone_to_first_user(): void
    {

        $userUpdate = $this->profileAddPhones(
            $this->landlord->user_cross_tenant_admin,
            [
                $this->temporary_phone_1,
            ]
        );

        $userUpdate->assertStatus(200);

        $this->assertContains(PhoneNumberParser::parse($this->temporary_phone_1), $userUpdate->json()['data']['phones']);
    }

    public function test_add_phone_to_second_user(): void
    {

        $userUpdate = $this->profileAddPhones(
            $this->landlord->user_cross_tenant_visitor,
            [
                $this->temporary_phone_2,
            ]
        );

        $userUpdate->assertStatus(200);

        $this->assertContains(PhoneNumberParser::parse($this->temporary_phone_2), $userUpdate->json()['data']['phones']);
        $this->assertCount(1, $userUpdate->json()['data']['phones']);

    }

    public function test_add_phones_repeated(): void
    {
        $firstUpdate = $this->profileAddPhones(
            $this->landlord->user_cross_tenant_visitor,
            [
                $this->temporary_phone_1,
            ]
        );
        $firstUpdate->assertStatus(200);

        $userUpdate = $this->profileAddPhones(
            $this->landlord->user_cross_tenant_visitor,
            [
                $this->temporary_phone_1,
            ]
        );

        $userUpdate->assertStatus(200);
        $this->assertContains(PhoneNumberParser::parse($this->temporary_phone_1), $userUpdate->json()['data']['phones']);
        $this->assertCount(1, $userUpdate->json()['data']['phones']);
    }

    public function test_remove_phone_from_firs_user(): void
    {
        $this->profileAddPhones(
            $this->landlord->user_cross_tenant_admin,
            [
                $this->temporary_phone_1,
            ]
        )->assertStatus(200);

        $userUpdate = $this->profileRemovePhone(
            $this->landlord->user_cross_tenant_admin,
            $this->temporary_phone_1,
        );

        $userUpdate->assertStatus(200);

        $this->assertNotContains(PhoneNumberParser::parse($this->temporary_phone_1), $userUpdate->json()['data']['phones']);
    }

    public function test_remove_phone_from_second_user(): void
    {
        $this->profileAddPhones(
            $this->landlord->user_cross_tenant_visitor,
            [
                $this->temporary_phone_2,
            ]
        )->assertStatus(200);

        $userUpdate = $this->profileRemovePhone(
            $this->landlord->user_cross_tenant_visitor,
            $this->temporary_phone_2,
        );
        $userUpdate->assertStatus(200);

        $this->assertNotContains(PhoneNumberParser::parse($this->temporary_phone_2), $userUpdate->json()['data']['phones']);
    }

    private function assertPasswordStateSynchronized(string $userId, string $plainPassword): void
    {
        $user = LandlordUser::query()->find($userId);

        $this->assertNotNull($user);
        $this->assertNull($user->getAttribute('password'));
        $this->assertNull($user->getAttribute('password_type'));

        foreach ($user->emails ?? [] as $email) {
            $credential = collect($user->credentials ?? [])
                ->first(static fn (array $credential): bool => ($credential['provider'] ?? null) === 'password'
                    && ($credential['subject'] ?? null) === strtolower((string) $email));

            $this->assertIsArray($credential);
            $this->assertTrue(Hash::check($plainPassword, (string) $credential['secret_hash']));
        }
    }

    private function issuePasswordResetTokenForCrossAdmin(): string
    {
        $response = $this->generateToken($this->landlord->user_cross_tenant_admin->email_1);
        $response->assertOk();
        $response->assertHeader('X-Api-Security-Domain', 'landlord_public_password_reset_token');

        $resetToken = $this->app->make(PasswordResetTokenService::class)
            ->latestIssuedTokenForTesting(
                userId: $this->landlord->user_cross_tenant_admin->user_id,
                broker: PasswordResetTokenService::LANDLORD_USERS_BROKER,
            );

        $token = DB::connection('landlord')->table('password_reset_tokens')
            ->where('user_id', $this->landlord->user_cross_tenant_admin->user_id)
            ->first();

        $this->assertNotNull($token);
        $this->assertEquals($this->landlord->user_cross_tenant_admin->user_id, $token->user_id);
        $this->assertIsString($resetToken);
        $this->assertNotSame('', $resetToken);
        $this->assertFalse(property_exists($token, 'token'));
        $this->assertTrue(Hash::check((string) $resetToken, (string) $token->token_hash));

        $this->landlord->user_cross_tenant_admin->password_reset_token = $resetToken;

        return $resetToken;
    }
}
