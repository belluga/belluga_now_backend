<?php

declare(strict_types=1);

namespace Tests\Unit\Application\LandlordUsers;

use App\Application\Auth\LandlordAuthenticationService;
use App\Application\LandlordUsers\LandlordPasswordCredentialBackfillService;
use App\Exceptions\Auth\InvalidCredentialsException;
use App\Models\Landlord\LandlordUser;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class LandlordPasswordCredentialBackfillServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private LandlordPasswordCredentialBackfillService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshLandlordAndTenantDatabases();
        $this->service = $this->app->make(LandlordPasswordCredentialBackfillService::class);
    }

    public function test_repair_backfills_legacy_only_landlord_users_and_removes_legacy_password_field(): void
    {
        $user = LandlordUser::create([
            'name' => 'Legacy Only',
            'emails' => ['legacy@example.org'],
        ]);

        LandlordUser::query()
            ->where('_id', $user->_id)
            ->update([
                'password' => Hash::make('Secret!234'),
                'password_type' => 'laravel',
                'credentials' => [],
            ]);

        $summary = $this->service->repair();

        $this->assertSame(1, $summary['totals']['legacy_only_normalized']);
        $freshUser = $user->fresh();
        $this->assertNotNull($freshUser);
        $this->assertNull($freshUser->getAttribute('password'));
        $credential = $this->passwordCredential($freshUser, 'legacy@example.org');
        $this->assertNotNull($credential);
        $this->assertTrue(Hash::check('Secret!234', (string) $credential['secret_hash']));
    }

    public function test_legacy_only_landlord_user_is_rejected_before_explicit_repair_and_accepted_afterward(): void
    {
        $user = LandlordUser::create([
            'name' => 'Legacy Login Repair',
            'emails' => ['legacy-login@example.org'],
        ]);

        LandlordUser::query()
            ->where('_id', $user->_id)
            ->update([
                'password' => Hash::make('Secret!234'),
                'password_type' => 'laravel',
                'credentials' => [],
            ]);

        $auth = $this->app->make(LandlordAuthenticationService::class);

        try {
            $auth->login('legacy-login@example.org', 'Secret!234', 'admin-client');
            $this->fail('Legacy-only landlord password must not authenticate before explicit repair.');
        } catch (InvalidCredentialsException) {
            $this->assertTrue(true);
        }

        $summary = $this->service->repair();

        $this->assertSame(1, $summary['totals']['legacy_only_normalized']);
        $result = $auth->login('legacy-login@example.org', 'Secret!234', 'admin-client');

        $this->assertSame('legacy-login@example.org', $result->user->emails[0]);
        $this->assertNotEmpty($result->plainTextToken);
        $this->assertNull($result->user->fresh()?->getAttribute('password'));
        $this->assertNull($result->user->fresh()?->getAttribute('password_type'));
    }

    public function test_repair_fills_missing_email_subject_credentials_and_prunes_removed_subjects(): void
    {
        $user = LandlordUser::create([
            'name' => 'Subject Gap',
            'emails' => ['primary@example.org', 'secondary@example.org'],
        ]);
        $canonicalHash = Hash::make('Secret!234');
        $user->syncCredential('password', 'primary@example.org', $canonicalHash);
        $user->syncCredential('password', 'orphan@example.org', $canonicalHash);

        LandlordUser::query()
            ->where('_id', $user->_id)
            ->update([
                'password' => Hash::make('Stale!234'),
                'password_type' => 'laravel',
            ]);

        $summary = $this->service->repair();

        $this->assertSame(1, $summary['totals']['missing_subjects_normalized']);
        $freshUser = $user->fresh();
        $this->assertNotNull($freshUser);
        $this->assertNull($freshUser->getAttribute('password'));
        $this->assertNotNull($this->passwordCredential($freshUser, 'primary@example.org'));
        $secondaryCredential = $this->passwordCredential($freshUser, 'secondary@example.org');
        $this->assertNotNull($secondaryCredential);
        $this->assertTrue(Hash::check('Secret!234', (string) $secondaryCredential['secret_hash']));
        $this->assertNull($this->passwordCredential($freshUser, 'orphan@example.org'));
    }

    public function test_repair_preserves_canonical_password_credential_when_legacy_password_hash_is_stale(): void
    {
        $user = LandlordUser::create([
            'name' => 'Split Brain',
            'emails' => ['admin@bellugasolutions.com.br'],
        ]);

        $canonicalHash = Hash::make('765432e1');
        $user->syncCredential('password', 'admin@bellugasolutions.com.br', $canonicalHash);

        LandlordUser::query()
            ->where('_id', $user->_id)
            ->update([
                'password' => Hash::make('LegacyStale!234'),
                'password_type' => 'laravel',
            ]);

        $summary = $this->service->repair();

        $this->assertSame(1, $summary['totals']['split_brain_normalized']);
        $freshUser = $user->fresh();
        $this->assertNotNull($freshUser);
        $this->assertNull($freshUser->getAttribute('password'));
        $this->assertNull($freshUser->getAttribute('password_type'));

        $credential = $this->passwordCredential($freshUser, 'admin@bellugasolutions.com.br');
        $this->assertNotNull($credential);
        $this->assertTrue(Hash::check('765432e1', (string) $credential['secret_hash']));
        $this->assertFalse(Hash::check('LegacyStale!234', (string) $credential['secret_hash']));
    }

    public function test_dry_run_reports_normalizable_drift_without_mutating_persisted_legacy_or_credentials(): void
    {
        $legacyOnly = LandlordUser::create([
            'name' => 'Dry Run Legacy Only',
            'emails' => ['dry-legacy@example.org'],
        ]);
        $legacyOnlyHash = Hash::make('Legacy!234');
        LandlordUser::query()
            ->where('_id', $legacyOnly->_id)
            ->update([
                'password' => $legacyOnlyHash,
                'password_type' => 'laravel',
                'credentials' => [],
            ]);

        $subjectGap = LandlordUser::create([
            'name' => 'Dry Run Subject Gap',
            'emails' => ['primary-gap@example.org', 'secondary-gap@example.org'],
        ]);
        $subjectGapHash = Hash::make('SubjectGap!234');
        $subjectGap->syncCredential('password', 'primary-gap@example.org', $subjectGapHash);
        $subjectGap->syncCredential('password', 'orphan-gap@example.org', $subjectGapHash);
        $subjectGapLegacyHash = Hash::make('SubjectGapLegacy!234');
        LandlordUser::query()
            ->where('_id', $subjectGap->_id)
            ->update([
                'password' => $subjectGapLegacyHash,
                'password_type' => 'laravel',
            ]);

        $splitBrain = LandlordUser::create([
            'name' => 'Dry Run Split Brain',
            'emails' => ['admin@bellugasolutions.com.br'],
        ]);
        $canonicalHash = Hash::make('765432e1');
        $splitBrain->syncCredential('password', 'admin@bellugasolutions.com.br', $canonicalHash);
        $splitBrainLegacyHash = Hash::make('LegacyStale!234');
        LandlordUser::query()
            ->where('_id', $splitBrain->_id)
            ->update([
                'password' => $splitBrainLegacyHash,
                'password_type' => 'laravel',
            ]);

        $summary = $this->service->repair(dryRun: true);

        $this->assertSame(3, $summary['totals']['inspected']);
        $this->assertSame(3, $summary['totals']['normalized']);
        $this->assertSame(1, $summary['totals']['legacy_only_normalized']);
        $this->assertSame(1, $summary['totals']['missing_subjects_normalized']);
        $this->assertSame(1, $summary['totals']['split_brain_normalized']);

        $freshLegacyOnly = $legacyOnly->fresh();
        $this->assertNotNull($freshLegacyOnly);
        $this->assertSame($legacyOnlyHash, $freshLegacyOnly->getAttribute('password'));
        $this->assertSame('laravel', $freshLegacyOnly->getAttribute('password_type'));
        $this->assertNull($this->passwordCredential($freshLegacyOnly, 'dry-legacy@example.org'));

        $freshSubjectGap = $subjectGap->fresh();
        $this->assertNotNull($freshSubjectGap);
        $this->assertSame($subjectGapLegacyHash, $freshSubjectGap->getAttribute('password'));
        $this->assertSame('laravel', $freshSubjectGap->getAttribute('password_type'));
        $this->assertNotNull($this->passwordCredential($freshSubjectGap, 'primary-gap@example.org'));
        $this->assertNull($this->passwordCredential($freshSubjectGap, 'secondary-gap@example.org'));
        $this->assertNotNull($this->passwordCredential($freshSubjectGap, 'orphan-gap@example.org'));

        $freshSplitBrain = $splitBrain->fresh();
        $this->assertNotNull($freshSplitBrain);
        $this->assertSame($splitBrainLegacyHash, $freshSplitBrain->getAttribute('password'));
        $this->assertSame('laravel', $freshSplitBrain->getAttribute('password_type'));
        $credential = $this->passwordCredential($freshSplitBrain, 'admin@bellugasolutions.com.br');
        $this->assertNotNull($credential);
        $this->assertTrue(Hash::check('765432e1', (string) $credential['secret_hash']));
        $this->assertFalse(Hash::check('LegacyStale!234', (string) $credential['secret_hash']));
    }

    public function test_unrelated_landlord_user_save_strips_legacy_password_state_without_overwriting_canonical_credential(): void
    {
        $user = LandlordUser::create([
            'name' => 'Split Brain Save',
            'emails' => ['admin@bellugasolutions.com.br'],
        ]);

        $canonicalHash = Hash::make('765432e1');
        $user->syncCredential('password', 'admin@bellugasolutions.com.br', $canonicalHash);

        LandlordUser::query()
            ->where('_id', $user->_id)
            ->update([
                'password' => Hash::make('LegacyStale!234'),
                'password_type' => 'laravel',
            ]);

        $savedUser = $user->fresh();
        $this->assertNotNull($savedUser);
        $savedUser->name = 'Split Brain Save Renamed';
        $savedUser->save();

        $freshUser = $user->fresh();
        $this->assertNotNull($freshUser);
        $this->assertNull($freshUser->getAttribute('password'));
        $this->assertNull($freshUser->getAttribute('password_type'));

        $credential = $this->passwordCredential($freshUser, 'admin@bellugasolutions.com.br');
        $this->assertNotNull($credential);
        $this->assertTrue(Hash::check('765432e1', (string) $credential['secret_hash']));
        $this->assertFalse(Hash::check('LegacyStale!234', (string) $credential['secret_hash']));
    }

    public function test_direct_legacy_password_assignment_is_stripped_without_creating_password_credential(): void
    {
        $user = LandlordUser::create([
            'name' => 'Direct Legacy Assignment',
            'emails' => ['direct-legacy@example.org'],
        ]);

        $user->password = Hash::make('Secret!234');
        $user->password_type = 'laravel';
        $user->save();

        $freshUser = $user->fresh();
        $this->assertNotNull($freshUser);
        $this->assertNull($freshUser->getAttribute('password'));
        $this->assertNull($freshUser->getAttribute('password_type'));
        $this->assertNull($this->passwordCredential($freshUser, 'direct-legacy@example.org'));
    }

    public function test_legacy_password_state_in_create_payload_is_stripped_without_creating_password_credential(): void
    {
        $user = LandlordUser::create([
            'name' => 'Create Payload Legacy Assignment',
            'emails' => ['create-legacy@example.org'],
            'password' => Hash::make('Secret!234'),
            'password_type' => 'laravel',
        ]);

        $freshUser = $user->fresh();
        $this->assertNotNull($freshUser);
        $this->assertNull($freshUser->getAttribute('password'));
        $this->assertNull($freshUser->getAttribute('password_type'));
        $this->assertNull($this->passwordCredential($freshUser, 'create-legacy@example.org'));
    }

    public function test_repair_skips_unrecoverable_user_without_credential_creation_or_runtime_auth_broadening(): void
    {
        $user = LandlordUser::create([
            'name' => 'Unrecoverable Password Authority',
            'emails' => ['unrecoverable@example.org'],
            'credentials' => [],
        ]);

        $this->assertLoginRejected('unrecoverable@example.org', 'Secret!234');

        $summary = $this->service->repair();

        $this->assertSame(1, $summary['totals']['inspected']);
        $this->assertSame(1, $summary['totals']['skipped_unrecoverable']);
        $this->assertSame(0, $summary['totals']['normalized']);
        $this->assertSame('unrecoverable', $summary['users'][0]['status']);
        $this->assertSame('missing_password_authority', $summary['users'][0]['reason']);

        $freshUser = $user->fresh();
        $this->assertNotNull($freshUser);
        $this->assertNull($freshUser->getAttribute('password'));
        $this->assertNull($freshUser->getAttribute('password_type'));
        $this->assertNull($this->passwordCredential($freshUser, 'unrecoverable@example.org'));
        $this->assertLoginRejected('unrecoverable@example.org', 'Secret!234');
    }

    public function test_repair_skips_users_with_conflicting_password_credential_hashes(): void
    {
        $user = LandlordUser::create([
            'name' => 'Conflicting Passwords',
            'emails' => ['primary@example.org', 'secondary@example.org'],
        ]);
        $user->syncCredential('password', 'primary@example.org', Hash::make('Secret!234'));
        $user->syncCredential('password', 'secondary@example.org', Hash::make('Another!234'));

        $summary = $this->service->repair();

        $this->assertSame(1, $summary['totals']['skipped_conflicts']);
        $this->assertSame('conflict', $summary['users'][0]['status']);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function passwordCredential(LandlordUser $user, string $subject): ?array
    {
        $credential = collect($user->fresh()?->credentials ?? [])
            ->first(static fn (array $credential): bool => ($credential['provider'] ?? null) === 'password'
                && ($credential['subject'] ?? null) === strtolower($subject));

        return is_array($credential) ? $credential : null;
    }

    private function assertLoginRejected(string $email, string $password): void
    {
        $auth = $this->app->make(LandlordAuthenticationService::class);

        try {
            $auth->login($email, $password, 'admin-client');
            $this->fail('Landlord password authentication unexpectedly succeeded.');
        } catch (InvalidCredentialsException) {
            $this->assertTrue(true);
        }
    }
}
