<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Application\Auth\LandlordAuthenticationService;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\PersonalAccessToken;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class LandlordPasswordSetCommandTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    public function test_landlord_password_set_command_updates_canonical_password_and_revokes_tokens(): void
    {
        $this->refreshLandlordAndTenantDatabases();

        $user = LandlordUser::create([
            'name' => 'Landlord Admin',
            'emails' => ['admin@example.org', 'secondary@example.org'],
            'identity_state' => 'registered',
        ]);
        $user->syncCredential('password', 'admin@example.org', Hash::make('Old!234'));
        $user->createToken('existing-admin-session');

        LandlordUser::query()
            ->where('_id', $user->_id)
            ->update([
                'password' => Hash::make('legacy-stale'),
                'password_type' => 'laravel',
            ]);

        $this->assertSame(1, PersonalAccessToken::query()->count());

        $this->artisan('landlord:password:set', [
            'email' => 'admin@example.org',
        ])
            ->expectsQuestion('Landlord password', 'Another!234')
            ->assertExitCode(0);
        $this->assertSame(0, PersonalAccessToken::query()->count());

        $freshUser = $user->fresh();
        $this->assertNotNull($freshUser);
        $this->assertNull($freshUser->getAttribute('password'));
        $this->assertNull($freshUser->getAttribute('password_type'));

        $this->assertPasswordCredentialMatches($freshUser, 'admin@example.org', 'Another!234');
        $this->assertPasswordCredentialMatches($freshUser, 'secondary@example.org', 'Another!234');

        $auth = $this->app->make(LandlordAuthenticationService::class);
        $login = $auth->login('secondary@example.org', 'Another!234', 'manual-test');

        $this->assertSame('admin@example.org', $login->user->emails[0]);
        $this->assertNotEmpty($login->plainTextToken);
    }

    public function test_landlord_password_set_command_fails_closed_for_unknown_email(): void
    {
        $this->refreshLandlordAndTenantDatabases();

        $this->artisan('landlord:password:set', [
            'email' => 'missing@example.org',
        ])
            ->expectsOutput('Landlord user not found for email [missing@example.org].')
            ->assertExitCode(1);
    }

    private function assertPasswordCredentialMatches(LandlordUser $user, string $subject, string $password): void
    {
        $credential = collect($user->credentials ?? [])
            ->first(static fn (array $credential): bool => ($credential['provider'] ?? null) === 'password'
                && ($credential['subject'] ?? null) === $subject);

        $this->assertIsArray($credential);
        $this->assertTrue(Hash::check($password, (string) ($credential['secret_hash'] ?? '')));
    }
}
