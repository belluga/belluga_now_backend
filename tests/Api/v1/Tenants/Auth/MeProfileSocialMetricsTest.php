<?php

namespace Tests\Api\v1\Tenants\Auth;

use App\Application\Auth\TenantScopedAccessTokenService;
use App\Models\Tenants\AccountUser;
use Belluga\Invites\Models\Tenants\InviteEdge;
use Belluga\Invites\Models\Tenants\PrincipalSocialMetric;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;

class MeProfileSocialMetricsTest extends TestCaseTenant
{
    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        PrincipalSocialMetric::query()->delete();
        InviteEdge::query()->delete();
    }

    public function test_me_profile_exposes_sender_side_invites_sent_and_invites_accepted(): void
    {
        $user = $this->createMetricsUser('Sender Metrics User', [
            'pending_invites' => 7,
            'confirmed_events' => 9,
            'favorites' => 4,
        ]);
        $this->createMetrics($user, sent: 2, accepted: 1, received: 3);

        $response = $this->getMe($user, 'tenant-me-sender-metrics');

        $response->assertOk();
        $response->assertJsonPath('data.social_score.invites_sent', 2);
        $response->assertJsonPath('data.social_score.invites_accepted', 1);
    }

    public function test_me_profile_social_metrics_do_not_use_pending_received_invites_or_confirmed_events(): void
    {
        $user = $this->createMetricsUser('Ignore Receiver Metrics User', [
            'pending_invites' => 99,
            'confirmed_events' => 42,
            'favorites' => 4,
        ]);
        $this->createMetrics($user, sent: 2, accepted: 1, received: 3);

        $response = $this->getMe($user, 'tenant-me-ignore-old-metrics');

        $response->assertOk();
        $response->assertJsonPath('data.social_score.invites_sent', 2);
        $response->assertJsonPath('data.social_score.invites_accepted', 1);
        $this->assertNotSame(
            $response->json('data.counters.pending_invites'),
            $response->json('data.social_score.invites_sent'),
            'Sent-invite metric must not be derived from pending received invites.',
        );
        $this->assertNotSame(
            $response->json('data.counters.confirmed_events'),
            $response->json('data.social_score.invites_accepted'),
            'Accepted-invite metric must not be derived from own confirmed events.',
        );
    }

    public function test_me_profile_sender_metrics_ignore_received_invites_with_different_counts(): void
    {
        $user = $this->createMetricsUser('Different Counts Metrics User');
        $this->createMetrics($user, sent: 2, accepted: 1, received: 3);

        $response = $this->getMe($user, 'tenant-me-different-counts');

        $response->assertOk();
        $response->assertJsonPath('data.social_score.invites_sent', 2);
        $response->assertJsonPath('data.social_score.invites_accepted', 1);
        $response->assertJsonPath('data.counters.pending_invites', 3);
    }

    public function test_me_profile_social_metrics_are_read_from_aggregate_without_invite_edge_scan(): void
    {
        $user = $this->createMetricsUser('Aggregate Metrics User');
        $this->createMetrics($user, sent: 2, accepted: 1, received: 3);
        InviteEdge::query()->create([
            'event_id' => 'event-1',
            'occurrence_id' => 'occurrence-1',
            'receiver_user_id' => (string) $user->_id,
            'receiver_account_profile_id' => 'profile-1',
            'inviter_principal' => ['kind' => 'user', 'principal_id' => 'other-user'],
            'issued_by_user_id' => 'other-user',
            'status' => 'pending',
            'credited_acceptance' => false,
        ]);

        DB::connection('tenant')->flushQueryLog();
        DB::connection('tenant')->enableQueryLog();

        $response = $this->getMe($user, 'tenant-me-aggregate-query');

        $response->assertOk();
        $response->assertJsonPath('data.social_score.invites_sent', 2);
        $response->assertJsonPath('data.social_score.invites_accepted', 1);

        $inviteEdgeQueries = collect(DB::connection('tenant')->getQueryLog())->filter(
            static fn (array $queryLog): bool => str_contains(json_encode($queryLog), 'invite_edges')
        );

        $this->assertCount(0, $inviteEdgeQueries, 'Profile metrics must not scan invite_edges.');
    }

    /**
     * @param  array<string, int>  $counters
     */
    private function createMetricsUser(string $name, array $counters = []): AccountUser
    {
        return AccountUser::query()->create([
            'name' => $name,
            'emails' => [fake()->unique()->safeEmail()],
            'phones' => [],
            'identity_state' => 'registered',
            'counters' => $counters,
        ]);
    }

    private function createMetrics(AccountUser $user, int $sent, int $accepted, int $received): void
    {
        PrincipalSocialMetric::query()->create([
            'principal_kind' => 'user',
            'principal_id' => (string) $user->_id,
            'invites_sent' => $sent,
            'credited_invite_acceptances' => $accepted,
            'pending_invites_received' => $received,
        ]);
    }

    private function getMe(AccountUser $user, string $tokenName): \Illuminate\Testing\TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "{$this->base_api_tenant}me",
            headers: [
                'Authorization' => 'Bearer '.$this->issueTenantScopedToken($user, $tokenName),
                'Content-Type' => 'application/json',
            ],
        );
    }

    private function issueTenantScopedToken(AccountUser $user, string $tokenName): string
    {
        return $this->app->make(TenantScopedAccessTokenService::class)
            ->issueForAccountUser($user, $tokenName, [])
            ->plainTextToken;
    }
}
