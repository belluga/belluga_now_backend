<?php

declare(strict_types=1);

namespace Tests\Feature\Push;

use App\Application\Accounts\AccountManagementService;
use App\Application\Accounts\AccountUserService;
use App\Application\Events\AttendanceCommitmentService;
use App\Application\Auth\TenantScopedAccessTokenService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Application\Push\PushAudienceEligibilityService;
use App\Domain\Events\Events\OccurrenceAttendanceConfirmed;
use App\Jobs\Push\ReconcilePushTokenTopicsJob;
use App\Jobs\Push\SyncEventConfirmedTopicMembershipJob;
use App\Jobs\Push\SyncFavoriteAccountProfileTopicMembershipJob;
use App\Application\Push\PushChannelNamingService;
use App\Application\Push\PushTopicMembershipService;
use App\Application\Push\PushUserTopicProjectionService;
use App\Integration\Push\PushUserGatewayAdapter;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountRoleTemplate;
use App\Models\Tenants\AccountUser;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Favorites\Models\Tenants\FavoriteEdge;
use Belluga\Favorites\Domain\Events\FavoriteAdded;
use Belluga\PushHandler\Contracts\FcmClientContract;
use Belluga\PushHandler\Contracts\FcmTopicSenderContract;
use Belluga\PushHandler\Contracts\PushAudienceEligibilityContract;
use Belluga\PushHandler\Contracts\PushPlanPolicyContract;
use Belluga\PushHandler\Contracts\PushPlanPolicyDecisionContract;
use Belluga\PushHandler\Domain\Events\PushDeviceRegistered;
use Belluga\PushHandler\Jobs\SendPushMessageJob;
use Belluga\PushHandler\Models\Tenants\PushCredential;
use Belluga\PushHandler\Models\Tenants\PushDevice;
use Belluga\PushHandler\Models\Tenants\PushDeliveryLog;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Belluga\PushHandler\Models\Tenants\TenantPushSettings;
use Belluga\PushHandler\Services\FcmHttpV1Client;
use Belluga\PushHandler\Services\PushDeliveryService;
use Belluga\PushHandler\Services\PushDeviceService;
use Belluga\PushHandler\Services\PushCredentialService;
use Belluga\PushHandler\Services\PushRecipientResolver;
use Belluga\PushHandler\Services\PushSettingsKernelBridge;
use Belluga\Settings\Models\Tenants\TenantSettings;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use MongoDB\BSON\UTCDateTime;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class PushMessageFlowTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    private static bool $bootstrapped = false;

    private Account $account;

    private AccountUser $operator;

    private AccountRoleTemplate $operatorRole;

    private AccountUserService $userService;

    private string $baseUrl;

    private string $tenantHost;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        [$this->account] = $this->seedAccountWithRole(['push-messages:*', 'push-settings:update']);
        $this->account->makeCurrent();
        $this->resetPushTestState();

        $this->userService = $this->app->make(AccountUserService::class);

        $this->operatorRole = $this->account->roleTemplates()->create([
            'name' => 'Push Operator',
            'permissions' => ['push-messages:*', 'push-settings:update'],
        ]);

        $this->operator = $this->userService->create($this->account, [
            'name' => 'Push Operator',
            'email' => 'push-operator@example.org',
            'password' => 'Secret!234',
        ], (string) $this->operatorRole->_id);

        $this->app->bind(PushAudienceEligibilityContract::class, static function () {
            return new class implements PushAudienceEligibilityContract
            {
                public function isEligible(
                    Authenticatable $user,
                    PushMessage $message,
                    array $audience,
                    array $context = []
                ): bool {
                    if (! $user instanceof AccountUser) {
                        return false;
                    }

                    $type = $audience['type'] ?? 'all_users';
                    if ($type === 'users') {
                        $ids = $audience['user_ids'] ?? [];

                        return in_array((string) $user->_id, $ids, true);
                    }

                    return $type === 'all_users';
                }
            };
        });

        $this->seedPushSettings();

        $tenant = $this->resolvePrimaryPushTenant();
        $tenant->makeCurrent();
        $this->tenantHost = (string) parse_url($tenant->getMainDomain(), PHP_URL_HOST);
        $this->withServerVariables([
            'HTTP_HOST' => $this->tenantHost,
        ]);
        $this->baseUrl = sprintf('api/v1/accounts/%s/push/messages', $this->account->slug);
    }

    private function resetPushTestState(): void
    {
        PushMessage::query()->delete();
        PushDevice::query()->delete();
        PushDeliveryLog::query()->delete();
        TenantSettings::query()->delete();
    }

    public function test_push_message_data_requires_auth(): void
    {
        $response = $this->getJson($this->baseUrl.'/missing/data');
        $response->assertStatus(401);
    }

    public function test_account_push_message_data_and_actions_reject_token_with_removed_account_binding(): void
    {
        $messageId = $this->createAccountPushMessageWithBearerToken();

        $token = $this->app->make(TenantScopedAccessTokenService::class)->issueForAccountUser(
            $this->operator,
            'account-push-removed-binding',
            [
                'push-messages:read',
            ],
            tenantId: (string) Tenant::current()?->_id,
            accountId: (string) $this->account->_id
        );
        $token->accessToken->setAttribute('account_id', null);
        $token->accessToken->save();

        $this->assertAccountPushMessageDataAndActionsRejectBearerToken($messageId, $token->plainTextToken);
    }

    public function test_account_push_message_data_and_actions_reject_account_bound_token_without_read_ability(): void
    {
        $messageId = $this->createAccountPushMessageWithBearerToken();

        $token = $this->app->make(TenantScopedAccessTokenService::class)->issueForAccountUser(
            $this->operator,
            'account-push-create-only',
            [
                'push-messages:create',
            ],
            tenantId: (string) Tenant::current()?->_id,
            accountId: (string) $this->account->_id
        );

        $this->assertAccountPushMessageDataAndActionsRejectBearerToken($messageId, $token->plainTextToken);
    }

    public function test_account_push_message_data_and_actions_accept_resource_wildcard_bearer_token(): void
    {
        $messageId = $this->createAccountPushMessageWithBearerToken(['push-messages:*']);

        $token = $this->app->make(TenantScopedAccessTokenService::class)->issueForAccountUser(
            $this->operator,
            'account-push-wildcard-read',
            [
                'push-messages:*',
            ],
            tenantId: (string) Tenant::current()?->_id,
            accountId: (string) $this->account->_id
        );

        $this->assertAccountPushMessageDataAndActionsAcceptBearerToken($messageId, $token->plainTextToken);
    }

    public function test_account_push_message_data_and_actions_reject_other_account_bearer_token(): void
    {
        $messageId = $this->createAccountPushMessageWithBearerToken();
        $otherAccount = Account::create([
            'name' => 'Other Account '.Str::uuid()->toString(),
            'document' => strtoupper(Str::random(14)),
        ]);
        $otherRole = $otherAccount->roleTemplates()->create([
            'name' => 'Other Push Operator',
            'permissions' => ['push-messages:*'],
        ]);
        $operator = $this->userService->create($otherAccount, [
            'name' => (string) $this->operator->name,
            'email' => (string) $this->operator->emails[0],
            'password' => 'Secret!234',
        ], (string) $otherRole->_id);

        $token = $this->app->make(TenantScopedAccessTokenService::class)->issueForAccountUser(
            $operator,
            'account-push-other-account',
            [
                'push-messages:*',
            ],
            tenantId: (string) Tenant::current()?->_id,
            accountId: (string) $otherAccount->_id
        );

        $this->assertAccountPushMessageDataAndActionsRejectBearerToken($messageId, $token->plainTextToken);
    }

    public function test_push_message_data_missing_returns_ok_false(): void
    {
        $this->actingAsOperator();

        $missingId = (string) new \MongoDB\BSON\ObjectId;

        $data = $this->getJson($this->baseUrl.'/'.$missingId.'/data');
        $data->assertOk();
        $data->assertJsonPath('ok', false);
        $data->assertJsonPath('reason', 'not_found');
    }

    public function test_push_message_create_and_fetch_data(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $this->operator->_id],
            ],
        ]);

        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $message = PushMessage::query()->where('internal_name', $payload['internal_name'])->first();
        $this->assertNotNull($message);
        $this->assertSame((string) $this->account->_id, (string) $message->partner_id);
        $messageId = (string) $message->_id;

        $this->withServerVariables([
            'HTTP_HOST' => $this->tenantHost,
        ]);
        $data = $this->getJson($this->baseUrl.'/'.$messageId.'/data');
        $data->assertOk();
        $data->assertJsonPath('ok', true);
        $data->assertJsonPath('push_message_id', $messageId);
    }

    public function test_push_message_data_forbidden_when_not_in_audience(): void
    {
        $this->actingAsOperator();
        $otherUser = $this->userService->create($this->account, [
            'name' => 'Audience Other User',
            'email' => 'push-audience-other-'.Str::uuid()->toString().'@example.org',
            'password' => 'Secret!234',
        ], (string) $this->operatorRole->_id);

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $otherUser->_id],
            ],
        ]);

        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $data = $this->getJson($this->baseUrl.'/'.$messageId.'/data');
        $data->assertStatus(404);
        $data->assertJsonPath('ok', false);
        $data->assertJsonPath('reason', 'not_found');
    }

    public function test_push_message_data_inactive_returns_ok_false(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'active' => false,
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $this->operator->_id],
            ],
        ]);

        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $data = $this->getJson($this->baseUrl.'/'.$messageId.'/data');
        $data->assertOk();
        $data->assertJsonPath('ok', false);
        $data->assertJsonPath('reason', 'inactive');
    }

    public function test_push_message_data_expired_returns_ok_false(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'delivery_deadline_at' => now()->addDay()->toIso8601String(),
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $this->operator->_id],
            ],
        ]);

        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $message = PushMessage::query()->where('internal_name', $payload['internal_name'])->firstOrFail();
        $message->delivery_deadline_at = now()->subDay()->toIso8601String();
        $message->save();

        $messageId = (string) $message->_id;

        $data = $this->getJson($this->baseUrl.'/'.$messageId.'/data');
        $data->assertOk();
        $data->assertJsonPath('ok', false);
        $data->assertJsonPath('reason', 'expired');
    }

    public function test_push_message_delete_archives_when_sent(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $this->operator->_id],
            ],
        ]);

        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);
        PushMessage::query()->where('_id', $messageId)->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $delete = $this->deleteJson($this->baseUrl.'/'.$messageId);
        $delete->assertOk();
        $delete->assertJsonPath('data.status', 'archived');
        $delete->assertJsonPath('data.active', false);
    }

    public function test_push_message_delete_hard_when_scheduled(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'delivery' => [
                'scheduled_at' => now()->addDay()->toIso8601String(),
            ],
        ]);

        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $delete = $this->deleteJson($this->baseUrl.'/'.$messageId);
        $delete->assertOk();
        $delete->assertJsonPath('ok', true);
    }

    public function test_push_message_actions_record_metrics(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $this->operator->_id],
            ],
        ]);

        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $actionPayload = [
            'action' => 'clicked',
            'step_index' => 0,
            'button_key' => 'cta',
            'idempotency_key' => 'click:'.$messageId,
        ];

        $action = $this->postJson($this->baseUrl.'/'.$messageId.'/actions', $actionPayload);
        $action->assertOk();

        $duplicate = $this->postJson($this->baseUrl.'/'.$messageId.'/actions', $actionPayload);
        $duplicate->assertOk();

        $message = PushMessage::query()->find($messageId);
        $this->assertNotNull($message);
        $metrics = $message->metrics ?? [];
        $this->assertEquals(1, $metrics['clicked_count'] ?? 0);
        $this->assertEquals(1, $metrics['unique_clicked_count'] ?? 0);
    }

    public function test_push_message_actions_record_opened_metrics(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $this->operator->_id],
            ],
        ]);

        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $action = $this->postJson($this->baseUrl.'/'.$messageId.'/actions', [
            'action' => 'opened',
            'step_index' => 0,
            'idempotency_key' => 'opened:'.$messageId,
        ]);

        $action->assertOk();

        $message = PushMessage::query()->find($messageId);
        $this->assertNotNull($message);
        $metrics = $message->metrics ?? [];
        $this->assertEquals(1, $metrics['opened_count'] ?? 0);
        $this->assertEquals(1, $metrics['unique_opened_count'] ?? 0);
    }

    public function test_push_message_actions_record_dismissed_metrics(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $this->operator->_id],
            ],
        ]);

        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $action = $this->postJson($this->baseUrl.'/'.$messageId.'/actions', [
            'action' => 'dismissed',
            'step_index' => 0,
            'idempotency_key' => 'dismissed:'.$messageId,
        ]);

        $action->assertOk();

        $message = PushMessage::query()->find($messageId);
        $this->assertNotNull($message);
        $metrics = $message->metrics ?? [];
        $this->assertEquals(1, $metrics['dismissed_count'] ?? 0);
        $this->assertEquals(1, $metrics['unique_dismissed_count'] ?? 0);
    }

    public function test_push_message_actions_record_step_viewed_metrics(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $this->operator->_id],
            ],
        ]);

        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $action = $this->postJson($this->baseUrl.'/'.$messageId.'/actions', [
            'action' => 'step_viewed',
            'step_index' => 1,
            'idempotency_key' => 'step_viewed:'.$messageId,
        ]);

        $action->assertOk();

        $message = PushMessage::query()->find($messageId);
        $this->assertNotNull($message);
        $metrics = $message->metrics ?? [];
        $this->assertEquals(1, $metrics['step_view_counts'][1] ?? 0);
    }

    public function test_push_message_actions_record_delivered_metrics(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $this->operator->_id],
            ],
        ]);

        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $action = $this->postJson($this->baseUrl.'/'.$messageId.'/actions', [
            'action' => 'delivered',
            'step_index' => 0,
            'idempotency_key' => 'delivered:'.$messageId,
        ]);

        $action->assertOk();

        $message = PushMessage::query()->find($messageId);
        $this->assertNotNull($message);
        $metrics = $message->metrics ?? [];
        $this->assertEquals(1, $metrics['delivered_count'] ?? 0);
    }

    public function test_push_message_actions_require_step_index(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $this->operator->_id],
            ],
        ]);

        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $action = $this->postJson($this->baseUrl.'/'.$messageId.'/actions', [
            'action' => 'opened',
            'idempotency_key' => 'opened-missing-step:'.$messageId,
        ]);

        $action->assertStatus(422);
        $action->assertJsonValidationErrors(['step_index']);
    }

    public function test_push_message_actions_clicked_requires_button_key(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $this->operator->_id],
            ],
        ]);

        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $action = $this->postJson($this->baseUrl.'/'.$messageId.'/actions', [
            'action' => 'clicked',
            'step_index' => 0,
            'idempotency_key' => 'clicked-missing-button:'.$messageId,
        ]);

        $action->assertStatus(422);
        $action->assertJsonValidationErrors(['button_key']);
    }

    public function test_push_message_actions_forbidden_when_not_eligible(): void
    {
        $this->actingAsOperator();
        $otherUser = $this->userService->create($this->account, [
            'name' => 'Action Other User',
            'email' => 'push-action-other-'.Str::uuid()->toString().'@example.org',
            'password' => 'Secret!234',
        ], (string) $this->operatorRole->_id);

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $otherUser->_id],
            ],
        ]);

        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $action = $this->postJson($this->baseUrl.'/'.$messageId.'/actions', [
            'action' => 'opened',
            'step_index' => 0,
            'idempotency_key' => 'opened:'.$messageId,
        ]);

        $action->assertStatus(403);
        $action->assertJsonPath('reason', 'forbidden');
    }

    public function test_push_message_actions_require_auth(): void
    {
        $response = $this->postJson($this->baseUrl.'/missing/actions', [
            'action' => 'opened',
            'step_index' => 0,
            'idempotency_key' => 'opened:missing',
        ]);

        $response->assertStatus(401);
    }

    public function test_push_message_list_and_update(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload();
        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $list = $this->getJson($this->baseUrl);
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));

        $update = $this->patchJson($this->baseUrl.'/'.$messageId, [
            'body_template' => 'Updated body',
        ]);
        $update->assertOk();
        $update->assertJsonPath('data.body_template', 'Updated body');
    }

    public function test_push_message_list_requires_tenant_access(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $restricted = LandlordUser::create([
            'name' => 'Restricted',
            'emails' => ['restricted@example.org'],
            'password' => 'Secret!234',
            'identity_state' => 'registered',
        ]);

        Sanctum::actingAs($restricted, ['push-messages:read']);

        $list = $this->getJson($this->baseUrl);
        $list->assertStatus(401);
    }

    public function test_account_push_crud_requires_auth(): void
    {
        $this->withServerVariables([
            'HTTP_HOST' => $this->tenantHost,
        ]);

        $list = $this->getJson($this->baseUrl);
        $list->assertStatus(401);

        $create = $this->postJson($this->baseUrl, $this->buildPayload());
        $create->assertStatus(401);
    }

    public function test_account_push_create_requires_ability(): void
    {
        $this->withServerVariables([
            'HTTP_HOST' => $this->tenantHost,
        ]);
        Sanctum::actingAs($this->operator, ['push-messages:read']);

        $create = $this->postJson($this->baseUrl, $this->buildPayload());
        $create->assertStatus(403);
    }

    public function test_tenant_push_crud_requires_auth(): void
    {
        $list = $this->getJson('api/v1/push/messages');
        $list->assertStatus(401);

        $create = $this->postJson('api/v1/push/messages', $this->buildPayload());
        $create->assertStatus(401);
    }

    public function test_tenant_push_create_requires_ability(): void
    {
        Sanctum::actingAs($this->operator, ['tenant-push-messages:read']);

        $create = $this->postJson('api/v1/push/messages', $this->buildPayload());
        $create->assertStatus(403);
    }

    public function test_tenant_push_route_accepts_account_bound_token_despite_stale_ambient_account(): void
    {
        $token = $this->app->make(TenantScopedAccessTokenService::class)->issueForAccountUser(
            $this->operator,
            'tenant-push-stale-ambient-account',
            [
                'tenant-push-messages:create',
                'tenant-push-messages:read',
            ],
            tenantId: (string) Tenant::current()?->_id,
            accountId: (string) $this->account->_id
        );

        $staleAccount = Account::create([
            'name' => 'Stale Ambient Account '.Str::uuid()->toString(),
            'document' => strtoupper(Str::random(14)),
        ]);
        $staleAccount->makeCurrent();
        $this->assertFalse($this->operator->fresh()->haveAccessTo($staleAccount));

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $this->operator->_id],
            ],
        ]);

        $create = $this
            ->withHeaders(['Authorization' => "Bearer {$token->plainTextToken}"])
            ->postJson('api/v1/push/messages', $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);
        $this->app['auth']->forgetGuards();

        $data = $this
            ->withHeaders(['Authorization' => "Bearer {$token->plainTextToken}"])
            ->getJson('api/v1/push/messages/'.$messageId.'/data');
        $data->assertOk();
        $data->assertJsonPath('ok', true);
        $data->assertJsonPath('push_message_id', $messageId);
    }

    public function test_tenant_push_list_requires_tenant_access(): void
    {
        $restricted = LandlordUser::create([
            'name' => 'Restricted Tenant',
            'emails' => ['restricted-tenant@example.org'],
            'password' => 'Secret!234',
            'identity_state' => 'registered',
        ]);

        Sanctum::actingAs($restricted, ['tenant-push-messages:read']);

        $list = $this->getJson('api/v1/push/messages');
        $list->assertStatus(403);
    }

    public function test_tenant_cross_tenant_data_and_actions_return_not_found(): void
    {
        $primaryTenant = Tenant::query()->where('subdomain', 'tenant-zeta')->firstOrFail();

        [$secondaryTenant, $secondaryOperator, $secondaryHost, $secondaryAccount] = $this->seedSecondaryTenantContext();

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $secondaryOperator->_id],
            ],
        ]);
        $this->withServerVariables(['HTTP_HOST' => $secondaryHost]);
        $this->assertNotSame((string) $secondaryAccount->_id, (string) Account::current()?->_id);
        $secondaryToken = $this->app->make(TenantScopedAccessTokenService::class)->issueForAccountUser(
            $secondaryOperator,
            'tenant-push-stale-account-context',
            [
                'tenant-push-messages:create',
                'tenant-push-messages:read',
            ],
            tenantId: (string) $secondaryTenant->_id,
            accountId: (string) $secondaryAccount->_id
        );

        $create = $this
            ->withHeaders(['Authorization' => "Bearer {$secondaryToken->plainTextToken}"])
            ->postJson('api/v1/push/messages', $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $primaryTenant->makeCurrent();
        $this->withServerVariables(['HTTP_HOST' => $this->tenantHost]);
        Sanctum::actingAs($this->operator, ['tenant-push-messages:read']);

        $data = $this->getJson('api/v1/push/messages/'.$messageId.'/data');
        $data->assertOk();
        $data->assertJsonPath('ok', false);
        $data->assertJsonPath('reason', 'not_found');

        $action = $this->postJson('api/v1/push/messages/'.$messageId.'/actions', [
            'action' => 'opened',
            'step_index' => 0,
            'idempotency_key' => 'opened:'.$messageId,
        ]);
        $action->assertStatus(404);

        $secondaryTenant->forgetCurrent();
    }

    public function test_tenant_cross_tenant_crud_returns_not_found(): void
    {
        $primaryTenant = Tenant::query()->where('subdomain', 'tenant-zeta')->firstOrFail();

        [$secondaryTenant, $secondaryOperator, $secondaryHost] = $this->seedSecondaryTenantContext();

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $secondaryOperator->_id],
            ],
        ]);
        $this->withServerVariables(['HTTP_HOST' => $secondaryHost]);
        Sanctum::actingAs($secondaryOperator, [
            'tenant-push-messages:create',
            'tenant-push-messages:read',
        ]);

        $create = $this->postJson('api/v1/push/messages', $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $primaryTenant->makeCurrent();
        $this->withServerVariables(['HTTP_HOST' => $this->tenantHost]);
        Sanctum::actingAs($this->operator, ['tenant-push-messages:read']);

        $show = $this->getJson('api/v1/push/messages/'.$messageId);
        $show->assertStatus(404);

        $secondaryTenant->forgetCurrent();
    }

    public function test_tenant_cross_tenant_credential_upsert_is_tenant_scoped(): void
    {
        $primaryTenant = Tenant::query()->where('subdomain', 'tenant-zeta')->firstOrFail();

        [$secondaryTenant, $secondaryOperator, $secondaryHost] = $this->seedSecondaryTenantContext();

        $this->withServerVariables(['HTTP_HOST' => $secondaryHost]);
        Sanctum::actingAs($secondaryOperator, ['tenant-push-credentials:update']);

        PushCredential::query()->delete();
        $create = $this->putJson('api/v1/settings/push/credentials', [
            'project_id' => 'secondary-project',
            'client_email' => 'secondary@example.org',
            'private_key' => 'secondary-key',
        ]);
        $create->assertCreated();

        $primaryTenant->makeCurrent();
        $this->withServerVariables(['HTTP_HOST' => $this->tenantHost]);
        Sanctum::actingAs($this->operator, ['tenant-push-credentials:update']);

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $primaryTenant->subdomain, $this->host);
        $update = $this->putJson($baseApiTenant.'settings/push/credentials', [
            'project_id' => 'primary-project',
            'client_email' => 'primary@example.org',
            'private_key' => 'primary-key',
        ]);
        $update->assertOk();

        $secondaryTenant->makeCurrent();
        $secondaryCredential = PushCredential::query()->first();
        $this->assertNotNull($secondaryCredential);
        $this->assertSame('secondary-project', (string) $secondaryCredential->project_id);

        $secondaryTenant->forgetCurrent();
    }

    public function test_push_message_scheduling_dispatches_with_delay(): void
    {
        $this->actingAsOperator();

        Bus::fake();

        $payload = $this->buildPayload([
            'delivery' => [
                'scheduled_at' => now()->addDay()->toIso8601String(),
            ],
        ]);

        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        Bus::assertDispatched(SendPushMessageJob::class, function (SendPushMessageJob $job): bool {
            return $job->delay !== null;
        });
    }

    public function test_push_message_create_validates_route_params(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'payload_template' => [
                'layoutType' => 'fullScreen',
                'closeBehavior' => 'after_action',
                'steps' => [
                    [
                        'slug' => 'intro',
                        'type' => 'copy',
                        'title' => 'Title',
                        'body' => 'Body text',
                    ],
                ],
                'buttons' => [
                    [
                        'label' => 'Agenda',
                        'action' => [
                            'type' => 'route',
                            'route_key' => 'agenda.detail',
                            'path_parameters' => [],
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->postJson($this->baseUrl, $payload);
        $response->assertStatus(422);
        $response->assertJsonFragment([
            'payload_template.buttons.0.action.path_parameters.slug' => ['Path parameter is required.'],
        ]);
    }

    public function test_push_message_create_allows_event_audience_without_qualifier(): void
    {
        $this->actingAsOperator();
        $event = Event::create([
            'type' => 'show',
            'title' => 'Push Event '.Str::uuid()->toString(),
            'date_time_start' => Carbon::now()->addDay(),
            'account_context_ids' => [(string) $this->account->_id],
            'created_by' => [
                'type' => 'account_user',
                'id' => (string) $this->operator->_id,
            ],
            'is_active' => true,
        ]);

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'event',
                'event_id' => (string) $event->_id,
            ],
        ]);

        $response = $this->postJson($this->baseUrl, $payload);
        $response->assertCreated();

        $message = PushMessage::query()->where('internal_name', $payload['internal_name'])->firstOrFail();
        $this->assertSame('event_confirmed', (string) data_get($message->audience, 'type'));
        $this->assertSame((string) $event->_id, (string) data_get($message->audience, 'event_id'));
    }

    public function test_push_message_create_rejects_legacy_all_audience_type(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'all',
            ],
        ]);

        $response = $this->postJson($this->baseUrl, $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'audience.type',
        ]);
    }

    public function test_push_message_create_rejects_event_qualifier_input(): void
    {
        $this->actingAsOperator();
        $event = Event::create([
            'type' => 'show',
            'title' => 'Push Event Qualifier '.Str::uuid()->toString(),
            'date_time_start' => Carbon::now()->addDay(),
            'account_context_ids' => [(string) $this->account->_id],
            'created_by' => [
                'type' => 'account_user',
                'id' => (string) $this->operator->_id,
            ],
            'is_active' => true,
        ]);

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'event',
                'event_id' => (string) $event->_id,
                'event_qualifier' => 'all',
            ],
        ]);

        $response = $this->postJson($this->baseUrl, $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'audience.event_qualifier',
        ]);
    }

    public function test_push_message_create_rejects_foreign_event_audience(): void
    {
        $this->actingAsOperator();
        $event = Event::create([
            'type' => 'show',
            'title' => 'Foreign Push Event '.Str::uuid()->toString(),
            'date_time_start' => Carbon::now()->addDay(),
            'account_context_ids' => [Str::uuid()->toString()],
            'created_by' => [
                'type' => 'account_user',
                'id' => (string) $this->operator->_id,
            ],
            'is_active' => true,
        ]);

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'event',
                'event_id' => (string) $event->_id,
            ],
        ]);

        $response = $this->postJson($this->baseUrl, $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'audience.event_id',
        ]);
    }

    public function test_push_message_create_rejects_all_users_audience_for_account_scope(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'all_users',
            ],
        ]);

        $response = $this->postJson($this->baseUrl, $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'audience.type',
        ]);
    }

    public function test_push_message_create_rejects_foreign_favorite_account_profile_audience(): void
    {
        $this->actingAsOperator();

        $foreignAccount = Account::create([
            'name' => 'Foreign Favorite Account '.Str::uuid()->toString(),
            'document' => strtoupper(Str::random(14)),
        ]);
        $foreignProfile = AccountProfile::create([
            'account_id' => (string) $foreignAccount->_id,
            'profile_type' => 'artist',
            'display_name' => 'Foreign Favorite Profile',
            'is_active' => true,
        ]);

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'favorite_account_profile',
                'account_profile_id' => (string) $foreignProfile->_id,
            ],
        ]);

        $response = $this->postJson($this->baseUrl, $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'audience.account_profile_id',
        ]);
    }

    public function test_push_message_create_validates_query_params(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'payload_template' => [
                'layoutType' => 'fullScreen',
                'closeBehavior' => 'after_action',
                'steps' => [
                    [
                        'slug' => 'intro',
                        'type' => 'copy',
                        'title' => 'Title',
                        'body' => 'Body text',
                    ],
                ],
                'buttons' => [
                    [
                        'label' => 'Agenda',
                        'action' => [
                            'type' => 'route',
                            'route_key' => 'agenda.search',
                            'path_parameters' => [],
                            'query_parameters' => [
                                'startSearchActive' => 'not-a-boolean',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->postJson($this->baseUrl, $payload);
        $response->assertStatus(422);
        $response->assertJsonFragment([
            'payload_template.buttons.0.action.query_parameters.startSearchActive' => [
                'The start search active field must be true or false.',
            ],
        ]);
    }

    public function test_push_message_create_requires_core_templates(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload();
        unset($payload['title_template'], $payload['body_template']);

        $response = $this->postJson($this->baseUrl, $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'title_template',
            'body_template',
        ]);
    }

    public function test_push_message_create_requires_steps(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload();
        unset($payload['payload_template']['steps']);

        $response = $this->postJson($this->baseUrl, $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'payload_template.steps',
        ]);
    }

    public function test_push_message_create_requires_step_content(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'payload_template' => [
                'layoutType' => 'fullScreen',
                'closeBehavior' => 'after_action',
                'steps' => [
                    [
                        'slug' => 'intro',
                        'type' => 'copy',
                        'title' => null,
                        'body' => null,
                    ],
                ],
            ],
        ]);

        $response = $this->postJson($this->baseUrl, $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'payload_template.steps.0.title',
        ]);
    }

    public function test_push_message_create_accepts_image_only_step(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'payload_template' => [
                'layoutType' => 'fullScreen',
                'closeBehavior' => 'after_action',
                'steps' => [
                    [
                        'slug' => 'intro',
                        'type' => 'copy',
                        'image' => [
                            'path' => 'https://example.com/hero.png',
                            'width' => 720,
                            'height' => 480,
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->postJson($this->baseUrl, $payload);
        $response->assertCreated();
    }

    public function test_push_message_create_sanitizes_html_body(): void
    {
        $this->actingAsOperator();

        $body = '<p>Hello <strong>World</strong><script>alert(1)</script>'
            .'<span style="color: #ff0000; font-weight: 700; font-size: 18px; background: blue;">Hi</span>'
            .'<img src="javascript:alert(1)" />'
            .'<img src="https://example.com/hero.png" width="120" height="80" onclick="nope" />'
            .'<ul><li>One</li></ul>'
            .'</p>';

        $payload = $this->buildPayload([
            'payload_template' => [
                'layoutType' => 'fullScreen',
                'closeBehavior' => 'after_action',
                'steps' => [
                    [
                        'slug' => 'intro',
                        'type' => 'copy',
                        'body' => $body,
                    ],
                ],
            ],
        ]);

        $response = $this->postJson($this->baseUrl, $payload);
        $response->assertCreated();

        $sanitized = $response->json('data.payload_template.steps.0.body');
        $this->assertIsString($sanitized);
        $this->assertStringContainsString('<strong>World</strong>', $sanitized);
        $this->assertStringContainsString('<span style="color: #ff0000; font-weight: 700; font-size: 18px">Hi</span>', $sanitized);
        $this->assertStringContainsString('https://example.com/hero.png', $sanitized);
        $this->assertStringContainsString('<ul>', $sanitized);
        $this->assertStringNotContainsString('<script>', $sanitized);
        $this->assertStringNotContainsString('alert(1)', $sanitized);
        $this->assertStringNotContainsString('background:', $sanitized);
        $this->assertStringNotContainsString('javascript:', $sanitized);
        $this->assertStringNotContainsString('onclick', $sanitized);
    }

    public function test_push_message_create_requires_close_behavior(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload();
        unset($payload['payload_template']['closeBehavior']);

        $response = $this->postJson($this->baseUrl, $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'payload_template.closeBehavior',
        ]);
    }

    public function test_push_message_update_rejects_close_on_last_step_action(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload();
        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $update = $this->patchJson($this->baseUrl.'/'.$messageId, [
            'payload_template' => [
                'layoutType' => 'fullScreen',
                'closeBehavior' => 'after_action',
                'closeOnLastStepAction' => true,
                'steps' => [
                    [
                        'slug' => 'intro',
                        'type' => 'copy',
                        'title' => 'Title',
                        'body' => 'Body text',
                    ],
                ],
            ],
        ]);

        $update->assertStatus(422);
        $update->assertJsonValidationErrors([
            'payload_template.closeOnLastStepAction',
        ]);
    }

    public function test_push_message_create_rejects_non_text_questions(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'payload_template' => [
                'layoutType' => 'fullScreen',
                'closeBehavior' => 'after_action',
                'steps' => [
                    [
                        'slug' => 'pick-one',
                        'type' => 'question',
                        'title' => 'Pick one',
                        'config' => [
                            'question_type' => 'single_select',
                            'layout' => 'list',
                            'options' => [
                                ['id' => 'a', 'label' => 'Option A'],
                                ['id' => 'b', 'label' => 'Option B'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->postJson($this->baseUrl, $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'payload_template.steps.0.config.question_type',
        ]);
    }

    public function test_push_message_create_rejects_selection_mode_on_questions(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'payload_template' => [
                'layoutType' => 'fullScreen',
                'closeBehavior' => 'after_action',
                'steps' => [
                    [
                        'slug' => 'text-question',
                        'type' => 'question',
                        'title' => 'Tell us more',
                        'config' => [
                            'question_type' => 'text',
                            'selection_mode' => 'multi',
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->postJson($this->baseUrl, $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'payload_template.steps.0.config.selection_mode',
        ]);
    }

    public function test_push_message_create_defaults_selector_selection_mode(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'payload_template' => [
                'layoutType' => 'fullScreen',
                'closeBehavior' => 'after_action',
                'steps' => [
                    [
                        'slug' => 'pick-tags',
                        'type' => 'selector',
                        'title' => 'Pick tags',
                        'config' => [
                            'selection_ui' => 'inline',
                            'layout' => 'list',
                            'options' => [
                                ['id' => 'a', 'label' => 'Option A'],
                                ['id' => 'b', 'label' => 'Option B'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();
        $create->assertJsonPath('data.payload_template.steps.0.config.selection_mode', 'single');
    }

    public function test_push_message_create_persists_payload_template_display_fields(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'payload_template' => [
                'title' => 'Onboarding Title',
                'body' => 'Onboarding Body',
                'image' => [
                    'path' => 'https://example.com/hero.png',
                    'width' => 720,
                    'height' => 480,
                ],
                'steps' => [
                    [
                        'slug' => 'intro',
                        'type' => 'copy',
                        'title' => 'Title',
                        'body' => 'Body text',
                        'gate' => [
                            'type' => 'selection_min',
                            'min_selected' => 2,
                            'onFail' => [
                                'toast' => 'Selecione pelo menos 2 itens.',
                            ],
                        ],
                        'buttons' => [
                            [
                                'label' => 'Continuar',
                                'continue_after_action' => true,
                                'action' => [
                                    'type' => 'custom',
                                    'custom_action' => 'test_action',
                                ],
                                'show_loading' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();
        $create->assertJsonPath('data.payload_template.title', 'Onboarding Title');
        $create->assertJsonPath('data.payload_template.body', 'Onboarding Body');
        $create->assertJsonPath('data.payload_template.image.path', 'https://example.com/hero.png');
        $create->assertJsonPath('data.payload_template.image.width', 720);
        $create->assertJsonPath('data.payload_template.image.height', 480);
        $create->assertJsonPath('data.payload_template.steps.0.gate.min_selected', 2);
        $create->assertJsonPath('data.payload_template.steps.0.buttons.0.continue_after_action', true);
    }

    public function test_push_message_update_persists_payload_template_display_fields(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload();
        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $update = $this->patchJson($this->baseUrl.'/'.$messageId, [
            'payload_template' => [
                'layoutType' => 'fullScreen',
                'closeBehavior' => 'after_action',
                'title' => 'Updated Title',
                'body' => 'Updated Body',
                'image' => [
                    'path' => 'https://example.com/updated.png',
                    'width' => 640,
                    'height' => 360,
                ],
                'steps' => [
                    [
                        'slug' => 'intro',
                        'type' => 'copy',
                        'title' => 'Title',
                        'body' => 'Body text',
                        'gate' => [
                            'type' => 'selection_min',
                            'min_selected' => 1,
                        ],
                        'buttons' => [
                            [
                                'label' => 'Continuar',
                                'continue_after_action' => false,
                                'action' => [
                                    'type' => 'custom',
                                    'custom_action' => 'test_action',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $update->assertOk();
        $update->assertJsonPath('data.payload_template.title', 'Updated Title');
        $update->assertJsonPath('data.payload_template.body', 'Updated Body');
        $update->assertJsonPath('data.payload_template.image.path', 'https://example.com/updated.png');
        $update->assertJsonPath('data.payload_template.image.width', 640);
        $update->assertJsonPath('data.payload_template.image.height', 360);
        $update->assertJsonPath('data.payload_template.steps.0.gate.min_selected', 1);
        $update->assertJsonPath('data.payload_template.steps.0.buttons.0.continue_after_action', false);
    }

    public function test_push_message_create_requires_audience_type(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload();
        unset($payload['audience']['type']);

        $response = $this->postJson($this->baseUrl, $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'audience.type',
        ]);
    }

    public function test_push_message_create_users_audience_requires_user_ids(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload();
        $payload['audience'] = [
            'type' => 'users',
        ];

        $response = $this->postJson($this->baseUrl, $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'audience.user_ids',
        ]);
    }

    public function test_push_message_create_rejects_multi_user_direct_audience(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $this->operator->_id, Str::uuid()->toString()],
            ],
        ]);

        $response = $this->postJson($this->baseUrl, $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'audience.user_ids',
        ]);
    }

    public function test_push_message_create_rejects_delivery_expires_at(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'delivery' => [
                'expires_at' => now()->addDay()->toIso8601String(),
            ],
        ]);

        $response = $this->postJson($this->baseUrl, $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'delivery.expires_at',
        ]);
    }

    public function test_push_message_create_rejects_past_deadline(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'delivery_deadline_at' => now()->subMinute()->toIso8601String(),
        ]);

        $response = $this->postJson($this->baseUrl, $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'delivery_deadline_at',
        ]);
    }

    public function test_tenant_push_settings_update_requires_tenant_access(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $visitor = LandlordUser::create([
            'name' => 'Visitor',
            'emails' => ['visitor@example.org'],
            'password' => 'Secret!234',
            'identity_state' => 'registered',
        ]);

        Sanctum::actingAs($visitor, ['push-settings:update']);

        $payload = [
            'throttles' => [],
            'max_ttl_days' => 30,
        ];

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $tenant->subdomain, $this->host);
        $response = $this->patchJson($baseApiTenant.'settings/push', $payload);
        $response->assertStatus(403);
    }

    public function test_tenant_push_settings_requires_push_config(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, ['push-settings:update']);

        $payload = [];

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $tenant->subdomain, $this->host);
        $response = $this->patchJson($baseApiTenant.'settings/push', $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'payload',
        ]);
    }

    public function test_tenant_push_settings_defaults_max_ttl_days(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();
        TenantPushSettings::query()->delete();

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, ['push-settings:update']);

        $payload = [
            'throttles' => [],
        ];

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $tenant->subdomain, $this->host);
        $response = $this->patchJson($baseApiTenant.'settings/push', $payload);
        $response->assertOk();
        $response->assertJsonPath('data.max_ttl_days', 7);
    }

    public function test_tenant_push_settings_patch_is_visible_in_kernel_values_endpoint(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, ['push-settings:update']);

        $payload = [
            'throttles' => [
                'per_minute' => 120,
            ],
            'max_ttl_days' => 21,
        ];

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $tenant->subdomain, $this->host);
        $patch = $this->patchJson($baseApiTenant.'settings/push', $payload);
        $patch->assertOk();
        $patch->assertJsonPath('data.max_ttl_days', 21);
        $patch->assertJsonPath('data.throttles.per_minute', 120);

        $kernelValues = $this->getJson(str_replace('/api/v1/', '/admin/api/v1/', $baseApiTenant).'settings/values');
        $kernelValues->assertOk();
        $kernelValues->assertJsonPath('data.push.max_ttl_days', 21);
        $kernelValues->assertJsonPath('data.push.throttles.per_minute', 120);
    }

    public function test_tenant_firebase_settings_update_requires_tenant_access(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $visitor = LandlordUser::create([
            'name' => 'Visitor',
            'emails' => ['visitor-firebase@example.org'],
            'password' => 'Secret!234',
            'identity_state' => 'registered',
        ]);

        Sanctum::actingAs($visitor, ['push-settings:update']);

        $payload = [
            'apiKey' => 'key',
            'appId' => 'app',
            'projectId' => 'project',
            'messagingSenderId' => 'sender',
            'storageBucket' => 'bucket',
        ];

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $tenant->subdomain, $this->host);
        $response = $this->patchJson($baseApiTenant.'settings/firebase', $payload);
        $response->assertStatus(403);
    }

    public function test_tenant_firebase_settings_requires_firebase_config(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, ['push-settings:update']);

        $payload = [];

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $tenant->subdomain, $this->host);
        $response = $this->patchJson($baseApiTenant.'settings/firebase', $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'payload',
        ]);
    }

    public function test_tenant_firebase_settings_patch_is_visible_in_kernel_values_endpoint(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, ['push-settings:update']);

        $payload = [
            'apiKey' => 'tenant-key',
            'appId' => 'tenant-app',
            'projectId' => 'tenant-project',
            'messagingSenderId' => 'tenant-sender',
            'storageBucket' => 'tenant-bucket',
        ];

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $tenant->subdomain, $this->host);
        $patch = $this->patchJson($baseApiTenant.'settings/firebase', $payload);
        $patch->assertOk();
        $patch->assertJsonPath('data.projectId', 'tenant-project');

        $kernelValues = $this->getJson(str_replace('/api/v1/', '/admin/api/v1/', $baseApiTenant).'settings/values');
        $kernelValues->assertOk();
        $kernelValues->assertJsonPath('data.firebase.projectId', 'tenant-project');
        $kernelValues->assertJsonPath('data.firebase.apiKey', 'tenant-key');
    }

    public function test_landlord_tenant_firebase_settings_admin_endpoints_use_kernel_namespace(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, ['push-settings:update']);

        $this->withServerVariables([
            'HTTP_HOST' => $this->host,
        ]);

        $payload = [
            'apiKey' => 'admin-key',
            'appId' => 'admin-app',
            'projectId' => 'admin-project',
            'messagingSenderId' => 'admin-sender',
            'storageBucket' => 'admin-bucket',
        ];

        $adminPath = sprintf('admin/api/v1/%s/settings/firebase', $tenant->slug);
        $patch = $this->patchJson($adminPath, $payload);
        $patch->assertOk();
        $patch->assertJsonPath('data.projectId', 'admin-project');

        $show = $this->getJson($adminPath);
        $show->assertOk();
        $show->assertJsonPath('data.projectId', 'admin-project');

        $tenant->makeCurrent();
        $settings = TenantSettings::current();
        $this->assertNotNull($settings);
        $firebase = $settings?->getAttribute('firebase') ?? [];
        $this->assertSame('admin-project', $firebase['projectId'] ?? null);
        $this->assertSame('admin-key', $firebase['apiKey'] ?? null);
    }

    public function test_tenant_route_types_update_normalizes_routes(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, ['push-settings:update']);

        $payload = [
            [
                'key' => 'agenda.detail',
                'path' => '/agenda/evento/:slug',
                'query_params' => ['event_id'],
            ],
        ];

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $tenant->subdomain, $this->host);
        $response = $this->patchJson($baseApiTenant.'settings/push/route_types', $payload);
        $response->assertOk();
        $response->assertJsonFragment([
            'key' => 'agenda.detail',
            'path_params' => ['slug'],
            'query_params' => [
                'event_id' => 'string',
            ],
        ]);

        $kernelValues = $this->getJson(str_replace('/api/v1/', '/admin/api/v1/', $baseApiTenant).'settings/values');
        $kernelValues->assertOk();
        $routes = $kernelValues->json('data.push.message_routes');
        $this->assertIsArray($routes);
        $detail = collect($routes)->firstWhere('key', 'agenda.detail');
        $this->assertIsArray($detail);
        $this->assertSame('/agenda/evento/:slug', $detail['path'] ?? null);
    }

    public function test_tenant_push_settings_rejects_route_and_type_fields(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, ['push-settings:update']);

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $tenant->subdomain, $this->host);
        $payload = [
            'push_message_routes' => [
                [
                    'key' => 'agenda.search',
                    'path' => '/agenda/search',
                ],
            ],
            'push_message_types' => [
                [
                    'key' => 'invite_received',
                    'label' => 'Invite Updated',
                ],
            ],
            'firebase' => true,
            'telemetry' => [
                [
                    'type' => 'mixpanel',
                    'token' => 'token',
                    'events' => ['invite_received'],
                ],
            ],
            'push' => true,
        ];

        $response = $this->patchJson($baseApiTenant.'settings/push', $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'firebase',
            'telemetry',
            'push_message_routes',
            'push_message_types',
            'push',
        ]);
    }

    public function test_tenant_route_types_patch_merges_by_key(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        TenantPushSettings::query()->delete();
        TenantPushSettings::create($this->buildTenantSettingsPayload([
            'push' => [
                'message_routes' => [
                    [
                        'key' => 'agenda.search',
                        'path' => '/agenda',
                        'path_params' => [],
                        'query_params' => [],
                    ],
                    [
                        'key' => 'agenda.detail',
                        'path' => '/agenda/evento/:slug',
                        'path_params' => ['slug'],
                        'query_params' => [],
                    ],
                ],
            ],
        ]));

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, ['push-settings:update']);

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $tenant->subdomain, $this->host);
        $payload = [
            [
                'key' => 'agenda.search',
                'path' => '/agenda/search',
            ],
            [
                'key' => 'agenda.new',
                'path' => '/agenda/new',
            ],
        ];

        $response = $this->patchJson($baseApiTenant.'settings/push/route_types', $payload);
        $response->assertOk();
        $response->assertJsonFragment(['key' => 'agenda.search', 'path' => '/agenda/search']);
        $response->assertJsonFragment(['key' => 'agenda.detail', 'path' => '/agenda/evento/:slug']);
        $response->assertJsonFragment(['key' => 'agenda.new', 'path' => '/agenda/new']);

        $kernelValues = $this->getJson(str_replace('/api/v1/', '/admin/api/v1/', $baseApiTenant).'settings/values');
        $kernelValues->assertOk();
        $routes = $kernelValues->json('data.push.message_routes');
        $this->assertIsArray($routes);
        $this->assertNotNull(collect($routes)->firstWhere('key', 'agenda.new'));
    }

    public function test_tenant_message_types_patch_merges_by_key(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        TenantPushSettings::query()->delete();
        TenantPushSettings::create($this->buildTenantSettingsPayload([
            'push' => [
                'message_types' => [
                    [
                        'key' => 'invite_received',
                        'label' => 'Invite Received',
                    ],
                    [
                        'key' => 'event_reminder',
                        'label' => 'Event Reminder',
                    ],
                ],
            ],
        ]));

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, ['push-settings:update']);

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $tenant->subdomain, $this->host);
        $payload = [
            [
                'key' => 'invite_received',
                'label' => 'Invite Updated',
            ],
            [
                'key' => 'new_type',
                'label' => 'New Type',
            ],
        ];

        $response = $this->patchJson($baseApiTenant.'settings/push/message_types', $payload);
        $response->assertOk();
        $response->assertJsonFragment(['key' => 'invite_received', 'label' => 'Invite Updated']);
        $response->assertJsonFragment(['key' => 'event_reminder', 'label' => 'Event Reminder']);
        $response->assertJsonFragment(['key' => 'new_type', 'label' => 'New Type']);

        $kernelValues = $this->getJson(str_replace('/api/v1/', '/admin/api/v1/', $baseApiTenant).'settings/values');
        $kernelValues->assertOk();
        $types = $kernelValues->json('data.push.message_types');
        $this->assertIsArray($types);
        $updated = collect($types)->firstWhere('key', 'invite_received');
        $this->assertIsArray($updated);
        $this->assertSame('Invite Updated', $updated['label'] ?? null);
    }

    public function test_tenant_route_types_soft_delete_by_key(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        TenantPushSettings::query()->delete();
        TenantPushSettings::create($this->buildTenantSettingsPayload([
            'push' => [
                'message_routes' => [
                    [
                        'key' => 'agenda.search',
                        'path' => '/agenda',
                        'path_params' => [],
                        'query_params' => [],
                    ],
                    [
                        'key' => 'agenda.detail',
                        'path' => '/agenda/evento/:slug',
                        'path_params' => ['slug'],
                        'query_params' => [],
                    ],
                ],
            ],
        ]));

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, ['push-settings:update']);

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $tenant->subdomain, $this->host);
        $payload = ['keys' => ['agenda.detail']];

        $response = $this->deleteJson($baseApiTenant.'settings/push/route_types', $payload);
        $response->assertOk();
        $response->assertJsonFragment(['key' => 'agenda.search', 'path' => '/agenda']);
        $response->assertJsonFragment(['key' => 'agenda.detail', 'active' => false]);

        $kernelValues = $this->getJson(str_replace('/api/v1/', '/admin/api/v1/', $baseApiTenant).'settings/values');
        $kernelValues->assertOk();
        $routes = $kernelValues->json('data.push.message_routes');
        $this->assertIsArray($routes);
        $deleted = collect($routes)->firstWhere('key', 'agenda.detail');
        $this->assertIsArray($deleted);
        $this->assertFalse((bool) ($deleted['active'] ?? true));
    }

    public function test_tenant_message_types_soft_delete_by_key(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        TenantPushSettings::query()->delete();
        TenantPushSettings::create($this->buildTenantSettingsPayload([
            'push' => [
                'message_types' => [
                    [
                        'key' => 'invite_received',
                        'label' => 'Invite Received',
                    ],
                    [
                        'key' => 'event_reminder',
                        'label' => 'Event Reminder',
                    ],
                ],
            ],
        ]));

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, ['push-settings:update']);

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $tenant->subdomain, $this->host);
        $payload = ['keys' => ['event_reminder']];

        $response = $this->deleteJson($baseApiTenant.'settings/push/message_types', $payload);
        $response->assertOk();
        $response->assertJsonFragment(['key' => 'invite_received', 'label' => 'Invite Received']);
        $response->assertJsonFragment(['key' => 'event_reminder', 'active' => false]);

        $kernelValues = $this->getJson(str_replace('/api/v1/', '/admin/api/v1/', $baseApiTenant).'settings/values');
        $kernelValues->assertOk();
        $types = $kernelValues->json('data.push.message_types');
        $this->assertIsArray($types);
        $deleted = collect($types)->firstWhere('key', 'event_reminder');
        $this->assertIsArray($deleted);
        $this->assertFalse((bool) ($deleted['active'] ?? true));
    }

    public function test_inactive_route_type_rejected_when_creating_message(): void
    {
        $this->actingAsOperator();

        TenantPushSettings::query()->delete();
        TenantPushSettings::create($this->buildTenantSettingsPayload([
            'push' => [
                'message_routes' => [
                    [
                        'key' => 'agenda.search',
                        'path' => '/agenda',
                        'path_params' => [],
                        'query_params' => [
                            'startSearchActive' => 'boolean',
                        ],
                        'active' => false,
                    ],
                ],
                'message_types' => [
                    [
                        'key' => 'invite_received',
                        'label' => 'Invite Received',
                    ],
                ],
            ],
        ]));

        $payload = $this->buildPayload([
            'payload_template' => [
                'layoutType' => 'fullScreen',
                'closeBehavior' => 'after_action',
                'steps' => [
                    [
                        'slug' => 'intro',
                        'type' => 'copy',
                        'title' => 'Title',
                        'body' => 'Body text',
                    ],
                ],
                'buttons' => [
                    [
                        'label' => 'Agenda',
                        'action' => [
                            'type' => 'route',
                            'route_key' => 'agenda.search',
                            'path_parameters' => [],
                            'query_parameters' => [
                                'startSearchActive' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->postJson($this->baseUrl, $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'payload_template.buttons.0.action.route_key' => 'Route key is not defined in tenant settings.',
        ]);
    }

    public function test_inactive_message_type_blocks_route_filtering(): void
    {
        $this->actingAsOperator();

        TenantPushSettings::query()->delete();
        TenantPushSettings::create($this->buildTenantSettingsPayload([
            'push' => [
                'message_routes' => [
                    [
                        'key' => 'agenda.search',
                        'path' => '/agenda',
                        'path_params' => [],
                        'query_params' => [
                            'startSearchActive' => 'boolean',
                        ],
                    ],
                ],
                'message_types' => [
                    [
                        'key' => 'invite_received',
                        'label' => 'Invite Received',
                        'allowed_route_keys' => ['agenda.search'],
                        'active' => false,
                    ],
                ],
            ],
        ]));

        $payload = $this->buildPayload([
            'payload_template' => [
                'layoutType' => 'fullScreen',
                'closeBehavior' => 'after_action',
                'steps' => [
                    ['title' => 'Title'],
                ],
                'buttons' => [
                    [
                        'label' => 'Agenda',
                        'action' => [
                            'type' => 'route',
                            'route_key' => 'agenda.search',
                            'path_parameters' => [],
                            'query_parameters' => [
                                'startSearchActive' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->postJson($this->baseUrl, $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'payload_template.buttons.0.action.route_key' => 'Route key is not allowed for this message type. No route keys are allowed for this message type.',
        ]);
    }

    public function test_push_message_create_rejects_route_key_not_allowed_for_type(): void
    {
        $this->actingAsOperator();

        TenantPushSettings::query()->delete();
        TenantPushSettings::create($this->buildTenantSettingsPayload([
            'push' => [
                'message_routes' => [
                    [
                        'key' => 'agenda.search',
                        'path' => '/agenda',
                        'path_params' => [],
                        'query_params' => [
                            'startSearchActive' => 'boolean',
                        ],
                    ],
                    [
                        'key' => 'agenda.detail',
                        'path' => '/agenda/evento/:slug',
                        'path_params' => ['slug'],
                        'query_params' => [],
                    ],
                ],
                'message_types' => [
                    [
                        'key' => 'invite_received',
                        'label' => 'Invite Received',
                        'allowed_route_keys' => ['agenda.detail'],
                    ],
                ],
            ],
        ]));

        $payload = $this->buildPayload([
            'payload_template' => [
                'layoutType' => 'fullScreen',
                'closeBehavior' => 'after_action',
                'steps' => [
                    ['title' => 'Title'],
                ],
                'buttons' => [
                    [
                        'label' => 'Agenda',
                        'action' => [
                            'type' => 'route',
                            'route_key' => 'agenda.search',
                            'path_parameters' => [],
                            'query_parameters' => [
                                'startSearchActive' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->postJson($this->baseUrl, $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'payload_template.buttons.0.action.route_key' => 'Route key is not allowed for this message type. Allowed route keys: agenda.detail.',
        ]);
    }

    public function test_tenant_push_status_not_configured(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();
        TenantPushSettings::query()->delete();

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, ['push-settings:update']);

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $tenant->subdomain, $this->host);
        $response = $this->getJson($baseApiTenant.'settings/push/status');
        $response->assertOk();
        $response->assertJsonPath('status', 'not_configured');
    }

    public function test_tenant_push_status_pending_tests(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();
        PushDeliveryLog::query()->delete();
        $this->seedPushSettings();

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, ['push-settings:update']);

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $tenant->subdomain, $this->host);
        $enable = $this->postJson($baseApiTenant.'settings/push/enable');
        $enable->assertOk();
        $response = $this->getJson($baseApiTenant.'settings/push/status');
        $response->assertOk();
        $response->assertJsonPath('status', 'pending_tests');
    }

    public function test_tenant_push_status_active(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();
        PushDeliveryLog::query()->delete();
        $this->seedPushSettings();

        PushDeliveryLog::create([
            'push_message_id' => (string) new \MongoDB\BSON\ObjectId,
            'batch_id' => 'batch-1',
            'token_hash' => 'token',
            'status' => 'accepted',
        ]);

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, ['push-settings:update']);

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $tenant->subdomain, $this->host);
        $enable = $this->postJson($baseApiTenant.'settings/push/enable');
        $enable->assertOk();
        $response = $this->getJson($baseApiTenant.'settings/push/status');
        $response->assertOk();
        $response->assertJsonPath('status', 'active');
    }

    public function test_tenant_telemetry_add_remove_enforces_unique_types(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();
        $this->seedTelemetrySettings([]);

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, ['telemetry-settings:update']);

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $tenant->subdomain, $this->host);

        $response = $this->postJson($baseApiTenant.'settings/telemetry', [
            'type' => 'mixpanel',
            'token' => 'token',
            'events' => ['invite_received'],
        ]);
        $response->assertOk();
        $response->assertJsonPath('data.0.type', 'mixpanel');
        $payload = $response->json();
        $this->assertContains('invite_received', $payload['available_events'] ?? []);

        $response = $this->postJson($baseApiTenant.'settings/telemetry', [
            'type' => 'mixpanel',
            'token' => 'token-updated',
            'events' => ['invite_received'],
        ]);
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.token', 'token-updated');

        $response = $this->postJson($baseApiTenant.'settings/telemetry', [
            'type' => 'webhook',
            'url' => 'https://example.org/hook',
            'events' => ['invite_received'],
        ]);
        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $payload = $response->json();
        $this->assertContains('invite_received', $payload['available_events'] ?? []);

        $response = $this->deleteJson($baseApiTenant.'settings/telemetry/mixpanel');
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.type', 'webhook');
    }

    public function test_tenant_telemetry_accepts_track_all_without_events(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();
        $this->seedTelemetrySettings([]);

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, ['telemetry-settings:update']);

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $tenant->subdomain, $this->host);

        $response = $this->postJson($baseApiTenant.'settings/telemetry', [
            'type' => 'mixpanel',
            'token' => 'token',
            'track_all' => true,
        ]);
        $response->assertOk();
        $response->assertJsonPath('data.0.type', 'mixpanel');
        $response->assertJsonPath('data.0.track_all', true);
        $payload = $response->json();
        $this->assertContains('invite_received', $payload['available_events'] ?? []);
    }

    public function test_tenant_admin_telemetry_endpoints_use_tenant_admin_routes(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();
        $this->seedTelemetrySettings([]);

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, ['telemetry-settings:update']);

        $this->withServerVariables([
            'HTTP_HOST' => sprintf('%s.%s', $tenant->subdomain, $this->host),
        ]);

        $adminPath = 'admin/api/v1/settings/telemetry';

        $store = $this->postJson($adminPath, [
            'type' => 'mixpanel',
            'token' => 'admin-mixpanel-token',
            'events' => ['invite_received'],
        ]);
        $store->assertOk();
        $store->assertJsonPath('data.0.type', 'mixpanel');
        $store->assertJsonPath('data.0.token', 'admin-mixpanel-token');

        $index = $this->getJson($adminPath);
        $index->assertOk();
        $index->assertJsonPath('data.0.type', 'mixpanel');
        $index->assertJsonPath('data.0.token', 'admin-mixpanel-token');

        $delete = $this->deleteJson($adminPath.'/mixpanel');
        $delete->assertOk();
        $delete->assertJsonCount(0, 'data');

        $tenant->makeCurrent();
        $settings = TenantSettings::current();
        $this->assertNotNull($settings);
        $telemetry = $settings?->getAttribute('telemetry') ?? [];
        $this->assertSame([], $telemetry['trackers'] ?? []);
    }

    public function test_tenant_push_enable_requires_config(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, ['push-settings:update']);

        TenantPushSettings::query()->delete();

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $tenant->subdomain, $this->host);
        $response = $this->postJson($baseApiTenant.'settings/push/enable');
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['firebase', 'push']);
    }

    public function test_tenant_push_enable_sets_enabled_true(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, ['push-settings:update']);

        TenantPushSettings::query()->delete();
        TenantPushSettings::create($this->buildTenantSettingsPayload());

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $tenant->subdomain, $this->host);
        $response = $this->postJson($baseApiTenant.'settings/push/enable');
        $response->assertOk();
        $response->assertJsonPath('data.enabled', true);
    }

    public function test_tenant_push_disable_sets_enabled_false(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, ['push-settings:update']);

        TenantPushSettings::query()->delete();
        TenantPushSettings::create($this->buildTenantSettingsPayload([
            'push' => ['enabled' => true],
        ]));

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $tenant->subdomain, $this->host);
        $response = $this->postJson($baseApiTenant.'settings/push/disable');
        $response->assertOk();
        $response->assertJsonPath('data.enabled', false);
    }

    public function test_plan_policy_blocks_dispatch_when_cannot_send(): void
    {
        $this->actingAsOperator();

        Bus::fake();

        $this->app->bind(PushPlanPolicyContract::class, static function () {
            return new class implements PushPlanPolicyContract
            {
                public function canSend(string $accountId, PushMessage $message, int $requestedUnits): bool
                {
                    return false;
                }
            };
        });

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $this->operator->_id],
            ],
        ]);

        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        Bus::assertNotDispatched(SendPushMessageJob::class);
    }

    public function test_create_returns_quota_decision_when_policy_provides(): void
    {
        $this->actingAsOperator();

        Bus::fake();

        $this->app->bind(PushPlanPolicyContract::class, static function () {
            return new class implements PushPlanPolicyContract, PushPlanPolicyDecisionContract
            {
                public function canSend(string $accountId, PushMessage $message, int $requestedUnits): bool
                {
                    return true;
                }

                public function quotaDecision(string $accountId, PushMessage $message, int $requestedUnits): array
                {
                    return [
                        'allowed' => true,
                        'limit' => 100,
                        'current_used' => 10,
                        'requested' => $requestedUnits,
                        'remaining_after' => 90,
                        'period' => 'monthly',
                    ];
                }
            };
        });

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $this->operator->_id],
            ],
        ]);

        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();
        $create->assertJsonPath('quota_decision.allowed', true);
        $create->assertJsonPath('quota_decision.period', 'monthly');
    }

    public function test_quota_check_returns_decision(): void
    {
        Sanctum::actingAs($this->operator, ['push-messages:send']);

        $profile = AccountProfile::query()->create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'artist',
            'display_name' => 'Quota Check Favorite Profile',
            'is_active' => true,
        ]);

        $response = $this->getJson(sprintf(
            'api/v1/accounts/%s/push/quota-check?audience[type]=favorite_account_profile&audience[account_profile_id]=%s',
            $this->account->slug,
            (string) $profile->_id
        ));

        $response->assertOk();
        $response->assertJsonPath('allowed', true);
        $response->assertJsonPath('requested', 1);
    }

    public function test_quota_check_invalid_input_returns422(): void
    {
        Sanctum::actingAs($this->operator, ['push-messages:send']);

        $response = $this->getJson(sprintf(
            'api/v1/accounts/%s/push/quota-check',
            $this->account->slug
        ));

        $response->assertStatus(422);
    }

    public function test_quota_check_rejects_legacy_audience_size_input(): void
    {
        Sanctum::actingAs($this->operator, ['push-messages:send']);

        $response = $this->getJson(sprintf(
            'api/v1/accounts/%s/push/quota-check?audience_size=5',
            $this->account->slug
        ));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'audience_size',
        ]);
    }

    public function test_fcm_options_invalid_key_returns422(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'fcm_options' => [
                'unknown_key' => 'nope',
            ],
        ]);

        $response = $this->postJson($this->baseUrl, $payload);
        $response->assertStatus(422);
    }

    public function test_fcm_options_data_size_limit_returns422(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'fcm_options' => [
                'data' => [
                    'blob' => str_repeat('a', 5000),
                ],
            ],
        ]);

        $response = $this->postJson($this->baseUrl, $payload);
        $response->assertStatus(422);
    }

    public function test_fcm_options_notification_title_too_long_returns422(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'fcm_options' => [
                'notification' => [
                    'title' => str_repeat('a', 256),
                ],
            ],
        ]);

        $response = $this->postJson($this->baseUrl, $payload);
        $response->assertStatus(422);
    }

    public function test_fcm_options_notification_body_too_long_returns422(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'fcm_options' => [
                'notification' => [
                    'body' => str_repeat('b', 1001),
                ],
            ],
        ]);

        $response = $this->postJson($this->baseUrl, $payload);
        $response->assertStatus(422);
    }

    public function test_external_action_missing_url_returns422(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'payload_template' => [
                'buttons' => [
                    [
                        'label' => 'External',
                        'action' => [
                            'type' => 'external',
                            'url' => null,
                            'open_mode' => 'in_app',
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->postJson($this->baseUrl, $payload);
        $response->assertStatus(422);
    }

    public function test_external_action_invalid_open_mode_returns422(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'payload_template' => [
                'buttons' => [
                    [
                        'label' => 'External',
                        'action' => [
                            'type' => 'external',
                            'url' => 'https://example.org',
                            'open_mode' => 'invalid',
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->postJson($this->baseUrl, $payload);
        $response->assertStatus(422);
    }

    public function test_tenant_push_message_crud_works(): void
    {
        Sanctum::actingAs($this->operator, [
            'tenant-push-messages:read',
            'tenant-push-messages:create',
            'tenant-push-messages:update',
            'tenant-push-messages:delete',
            'tenant-push-messages:send',
        ]);

        $payload = $this->buildPayload();
        $create = $this->postJson('api/v1/push/messages', $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $list = $this->getJson('api/v1/push/messages');
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));

        $update = $this->patchJson('api/v1/push/messages/'.$messageId, [
            'body_template' => 'Tenant update',
        ]);
        $update->assertOk();
        $update->assertJsonPath('data.body_template', 'Tenant update');
    }

    public function test_tenant_push_message_update_accepts_all_users_audience_contract(): void
    {
        Sanctum::actingAs($this->operator, [
            'tenant-push-messages:create',
            'tenant-push-messages:update',
        ]);

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $this->operator->_id],
            ],
        ]);

        $create = $this->postJson('api/v1/push/messages', $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $update = $this->patchJson('api/v1/push/messages/'.$messageId, [
            'audience' => [
                'type' => 'all_users',
            ],
        ]);

        $update->assertOk();
        $update->assertJsonPath('data.audience.type', 'all_users');
    }

    public function test_tenant_message_data_forbidden_when_not_eligible(): void
    {
        Sanctum::actingAs($this->operator, [
            'tenant-push-messages:create',
            'tenant-push-messages:read',
        ]);
        $otherUser = $this->userService->create($this->account, [
            'name' => 'Tenant Data Other User',
            'email' => 'tenant-push-data-other-'.Str::uuid()->toString().'@example.org',
            'password' => 'Secret!234',
        ], (string) $this->operatorRole->_id);

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $otherUser->_id],
            ],
        ]);

        $create = $this->postJson('api/v1/push/messages', $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $data = $this->getJson('api/v1/push/messages/'.$messageId.'/data');
        $data->assertStatus(404);
        $data->assertJsonPath('reason', 'not_found');
    }

    public function test_tenant_message_actions_forbidden_when_not_eligible(): void
    {
        Sanctum::actingAs($this->operator, [
            'tenant-push-messages:create',
            'tenant-push-messages:read',
        ]);
        $otherUser = $this->userService->create($this->account, [
            'name' => 'Tenant Action Other User',
            'email' => 'tenant-push-action-other-'.Str::uuid()->toString().'@example.org',
            'password' => 'Secret!234',
        ], (string) $this->operatorRole->_id);

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $otherUser->_id],
            ],
        ]);

        $create = $this->postJson('api/v1/push/messages', $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $action = $this->postJson('api/v1/push/messages/'.$messageId.'/actions', [
            'action' => 'opened',
            'step_index' => 0,
            'idempotency_key' => 'opened:'.$messageId,
        ]);

        $action->assertStatus(403);
        $action->assertJsonPath('reason', 'forbidden');
    }

    public function test_audience_eligibility_contract_deny_blocks_data(): void
    {
        $this->actingAsOperator();

        $this->app->bind(PushAudienceEligibilityContract::class, static function () {
            return new class implements PushAudienceEligibilityContract
            {
                public function isEligible(
                    Authenticatable $user,
                    PushMessage $message,
                    array $audience,
                    array $context = []
                ): bool {
                    return false;
                }
            };
        });

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $this->operator->_id],
            ],
        ]);

        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $data = $this->getJson($this->baseUrl.'/'.$messageId.'/data');
        $data->assertStatus(404);
        $data->assertJsonPath('reason', 'not_found');
    }

    public function test_audience_eligibility_contract_override_allows_data(): void
    {
        $this->actingAsOperator();
        $otherUser = $this->userService->create($this->account, [
            'name' => 'Eligibility Override User',
            'email' => 'push-eligibility-override-'.Str::uuid()->toString().'@example.org',
            'password' => 'Secret!234',
        ], (string) $this->operatorRole->_id);

        $this->app->bind(PushAudienceEligibilityContract::class, static function () {
            return new class implements PushAudienceEligibilityContract
            {
                public function isEligible(
                    Authenticatable $user,
                    PushMessage $message,
                    array $audience,
                    array $context = []
                ): bool {
                    return true;
                }
            };
        });

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $otherUser->_id],
            ],
        ]);

        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $data = $this->getJson($this->baseUrl.'/'.$messageId.'/data');
        $data->assertOk();
        $data->assertJsonPath('ok', true);
    }

    public function test_transactional_send_requires_transactional_type(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'type' => 'invite_received',
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $this->operator->_id],
            ],
        ]);

        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $send = $this->postJson($this->baseUrl.'/'.$messageId.'/send');

        $send->assertStatus(422);
    }

    public function test_tenant_credentials_endpoints_require_permission(): void
    {
        Sanctum::actingAs($this->operator, ['tenant-push-credentials:read']);

        $response = $this->putJson('api/v1/settings/push/credentials', [
            'project_id' => 'project-id',
            'client_email' => 'client@example.org',
            'private_key' => 'secret',
        ]);

        $response->assertStatus(403);
    }

    public function test_tenant_credential_upsert_creates_and_updates_single_record(): void
    {
        Sanctum::actingAs($this->operator, ['tenant-push-credentials:update']);

        PushCredential::query()->delete();
        $create = $this->putJson('api/v1/settings/push/credentials', [
            'project_id' => 'project-id',
            'client_email' => 'client@example.org',
            'private_key' => 'secret',
        ]);

        $create->assertCreated();
        $create->assertJsonMissing(['private_key']);
        $credentialId = $create->json('data.id');

        $stored = DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('push_credentials')
            ->findOne(['_id' => new \MongoDB\BSON\ObjectId($credentialId)]);
        $this->assertNotNull($stored);
        $this->assertNotSame('secret', (string) ($stored['private_key'] ?? ''));

        $update = $this->putJson('api/v1/settings/push/credentials', [
            'project_id' => 'project-id',
            'client_email' => 'client@example.org',
            'private_key' => 'updated-secret',
        ]);

        $update->assertOk();
        $update->assertJsonPath('data.id', $credentialId);
        $this->assertSame(1, PushCredential::query()->count());
    }

    public function test_tenant_credential_upsert_recovers_from_corrupted_private_key_ciphertext(): void
    {
        Sanctum::actingAs($this->operator, ['tenant-push-credentials:update']);

        PushCredential::query()->delete();

        $id = new \MongoDB\BSON\ObjectId;
        $now = new UTCDateTime(now());
        DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('push_credentials')
            ->insertOne([
                '_id' => $id,
                'project_id' => 'legacy-project',
                'client_email' => 'legacy@example.org',
                'private_key' => 'corrupted-ciphertext',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        $update = $this->putJson('api/v1/settings/push/credentials', [
            'project_id' => 'project-id',
            'client_email' => 'client@example.org',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nupdated-secret\n-----END PRIVATE KEY-----",
        ]);

        $update->assertOk();
        $update->assertJsonPath('data.id', (string) $id);
        $this->assertSame(1, PushCredential::query()->count());

        $credential = PushCredential::query()->find($id);
        $this->assertNotNull($credential);
        $this->assertSame('project-id', $credential->project_id);
        $this->assertSame('client@example.org', $credential->client_email);
        $this->assertStringContainsString('BEGIN PRIVATE KEY', $credential->private_key);
    }

    public function test_tenant_credentials_index_returns_without_private_key(): void
    {
        Sanctum::actingAs($this->operator, ['tenant-push-credentials:update']);

        PushCredential::query()->delete();
        $credential = PushCredential::create([
            'project_id' => 'project-id',
            'client_email' => 'client@example.org',
            'private_key' => 'secret',
        ]);

        Sanctum::actingAs($this->operator, ['tenant-push-credentials:read']);

        $response = $this->getJson('api/v1/settings/push/credentials');
        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains((string) $credential->_id, $ids);
        $response->assertJsonMissing(['private_key']);
    }

    public function test_tenant_credentials_index_returns_conflict_when_multiple(): void
    {
        Sanctum::actingAs($this->operator, ['tenant-push-credentials:read']);

        PushCredential::query()->delete();
        PushCredential::create([
            'project_id' => 'project-id',
            'client_email' => 'client@example.org',
            'private_key' => 'secret',
        ]);
        PushCredential::create([
            'project_id' => 'project-id-2',
            'client_email' => 'client2@example.org',
            'private_key' => 'secret-2',
        ]);

        $response = $this->getJson('api/v1/settings/push/credentials');
        $response->assertStatus(409);
    }

    public function test_tenant_credentials_upsert_returns_conflict_when_multiple(): void
    {
        Sanctum::actingAs($this->operator, ['tenant-push-credentials:update']);

        PushCredential::query()->delete();
        PushCredential::create([
            'project_id' => 'project-id',
            'client_email' => 'client@example.org',
            'private_key' => 'secret',
        ]);
        PushCredential::create([
            'project_id' => 'project-id-2',
            'client_email' => 'client2@example.org',
            'private_key' => 'secret-2',
        ]);

        $response = $this->putJson('api/v1/settings/push/credentials', [
            'project_id' => 'project-id-3',
            'client_email' => 'client3@example.org',
            'private_key' => 'secret-3',
        ]);
        $response->assertStatus(409);
    }

    public function test_tenant_push_status_returns_conflict_when_multiple_credentials(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();
        TenantPushSettings::query()->delete();
        PushCredential::query()->delete();

        PushCredential::create([
            'project_id' => 'project-id',
            'client_email' => 'client@example.org',
            'private_key' => 'secret',
        ]);
        PushCredential::create([
            'project_id' => 'project-id-2',
            'client_email' => 'client2@example.org',
            'private_key' => 'secret-2',
        ]);

        TenantPushSettings::create($this->buildTenantSettingsPayload());

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, ['push-settings:update']);

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $tenant->subdomain, $this->host);
        $enable = $this->postJson($baseApiTenant.'settings/push/enable');
        $enable->assertOk();
        $response = $this->getJson($baseApiTenant.'settings/push/status');
        $response->assertStatus(409);
    }

    public function test_tenant_credential_validation_returns422(): void
    {
        Sanctum::actingAs($this->operator, ['tenant-push-credentials:update']);

        $response = $this->putJson('api/v1/settings/push/credentials', [
            'project_id' => 'project-id',
            'client_email' => 'client@example.org',
        ]);

        $response->assertStatus(422);
    }

    public function test_tenant_settings_does_not_expose_firebase_credentials_id(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, ['push-settings:update']);

        $payload = [
            'apiKey' => 'key',
            'appId' => 'app',
            'projectId' => 'project',
            'messagingSenderId' => 'sender',
            'storageBucket' => 'bucket',
        ];

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $tenant->subdomain, $this->host);
        $response = $this->patchJson($baseApiTenant.'settings/firebase', $payload);
        $response->assertOk();
        $response->assertJsonMissing(['firebase_credentials_id']);
    }

    public function test_delivery_logs_have_no_ttl_index(): void
    {
        $database = DB::connection('tenant')->getDatabase();
        $indexes = iterator_to_array(
            $database->selectCollection('push_delivery_logs')->listIndexes()
        );

        $this->assertNotEmpty($indexes);

        foreach ($indexes as $index) {
            $this->assertArrayNotHasKey('expireAfterSeconds', (array) $index);
        }
    }

    public function test_delivery_service_logs_partial_failures(): void
    {
        $this->app->bind(FcmClientContract::class, static function () {
            return new class implements FcmClientContract
            {
                public function send(
                    PushMessage $message,
                    array $tokens,
                    string $messageInstanceId,
                    Carbon $expiresAt,
                    int $ttlMinutes
                ): array {
                    return [
                        'accepted_count' => 1,
                        'responses' => [
                            [
                                'token' => $tokens[0] ?? '',
                                'status' => 'accepted',
                                'provider_message_id' => 'abc',
                            ],
                            [
                                'token' => $tokens[1] ?? '',
                                'status' => 'failed',
                                'error_code' => 'UNAVAILABLE',
                                'error_message' => 'unavailable',
                            ],
                        ],
                    ];
                }
            };
        });

        PushDeliveryLog::query()->delete();
        $message = PushMessage::create($this->buildPayload());
        $service = $this->app->make(PushDeliveryService::class);
        $service->deliver($message, ['token-1', 'token-2']);

        $logs = PushDeliveryLog::query()->get();
        $this->assertCount(2, $logs);
        $this->assertNotNull($logs->first()->expires_at ?? null);
        $this->assertNotNull($logs->first()->ttl_minutes ?? null);
        $statuses = $logs->pluck('status')->all();
        $this->assertContains('accepted', $statuses);
        $this->assertContains('failed', $statuses);
    }

    public function test_delivery_service_caps_expires_at_to_deadline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $this->app->bind(FcmClientContract::class, static function () {
            return new class implements FcmClientContract
            {
                public function send(
                    PushMessage $message,
                    array $tokens,
                    string $messageInstanceId,
                    Carbon $expiresAt,
                    int $ttlMinutes
                ): array {
                    return [
                        'accepted_count' => count($tokens),
                        'responses' => array_map(static fn (string $token): array => [
                            'token' => $token,
                            'status' => 'accepted',
                            'provider_message_id' => 'msg',
                        ], $tokens),
                    ];
                }
            };
        });

        PushDeliveryLog::query()->delete();
        $deadline = Carbon::now()->addMinutes(15);
        $message = PushMessage::create($this->buildPayload([
            'type' => 'transactional',
            'delivery_deadline_at' => $deadline->toIso8601String(),
        ]));

        $service = $this->app->make(PushDeliveryService::class);
        $service->deliver($message, ['token-1']);

        $log = PushDeliveryLog::query()->firstOrFail();
        $this->assertSame($deadline->toISOString(), $log->expires_at->toISOString());
        $this->assertSame(60, $log->ttl_minutes);

        Carbon::setTestNow();
    }

    public function test_delivery_service_rejects_past_deadline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        try {
            $this->expectException(ValidationException::class);
            $this->expectExceptionMessage('Delivery deadline must be in the future.');

            $message = PushMessage::create($this->buildPayload([
                'delivery_deadline_at' => Carbon::now()->subMinute()->toIso8601String(),
            ]));

            $service = $this->app->make(PushDeliveryService::class);
            $service->deliver($message, ['token-1']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_delivery_service_rejects_ttl_beyond_max(): void
    {
        $originalTtl = config('belluga_push_handler.delivery_ttl_minutes.transactional');
        config([
            'belluga_push_handler.delivery_ttl_minutes.transactional' => 60 * 24 * 40,
        ]);

        try {
            $this->expectException(ValidationException::class);
            $this->expectExceptionMessage('Computed TTL exceeds max allowed TTL');

            $message = PushMessage::create($this->buildPayload([
                'type' => 'transactional',
            ]));

            $service = $this->app->make(PushDeliveryService::class);
            $service->deliver($message, ['token-1']);
        } finally {
            config([
                'belluga_push_handler.delivery_ttl_minutes.transactional' => $originalTtl,
            ]);
        }
    }

    public function test_delivery_service_uses_schema_default_max_ttl_when_setting_is_not_persisted(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        TenantPushSettings::query()->delete();
        TenantPushSettings::create([
            'firebase' => [
                'apiKey' => 'key',
                'appId' => 'app',
                'projectId' => 'project',
                'messagingSenderId' => 'sender',
                'storageBucket' => 'bucket',
            ],
            'push' => [
                'message_types' => [
                    [
                        'key' => 'transactional',
                        'label' => 'Transactional',
                    ],
                ],
                'message_routes' => [],
            ],
        ]);

        $originalTtl = config('belluga_push_handler.delivery_ttl_minutes.transactional');
        config([
            'belluga_push_handler.delivery_ttl_minutes.transactional' => 60 * 24 * 8,
        ]);

        try {
            $this->expectException(ValidationException::class);
            $this->expectExceptionMessage('Computed TTL exceeds max allowed TTL of 7 days.');

            $message = PushMessage::create($this->buildPayload([
                'type' => 'transactional',
            ]));

            $service = $this->app->make(PushDeliveryService::class);
            $service->deliver($message, ['token-1']);
        } finally {
            config([
                'belluga_push_handler.delivery_ttl_minutes.transactional' => $originalTtl,
            ]);
        }
    }

    public function test_delivery_service_chunks_tokens_by_direct_send_chunk_size_config(): void
    {
        config(['belluga_push_handler.fcm.direct_send_chunk_size' => 500]);

        $batches = [];
        $this->app->bind(FcmClientContract::class, function () use (&$batches) {
            return new class($batches) implements FcmClientContract
            {
                /**
                 * @param  array<int, int>  $batches
                 */
                public function __construct(private array &$batches) {}

                public function send(
                    PushMessage $message,
                    array $tokens,
                    string $messageInstanceId,
                    Carbon $expiresAt,
                    int $ttlMinutes
                ): array {
                    $this->batches[] = count($tokens);

                    return [
                        'accepted_count' => count($tokens),
                        'responses' => [],
                    ];
                }
            };
        });

        $message = PushMessage::create($this->buildPayload());
        $service = $this->app->make(PushDeliveryService::class);

        $tokens = [];
        for ($i = 1; $i <= 1200; $i++) {
            $tokens[] = 'token-'.$i;
        }

        $response = $service->deliver($message, $tokens);

        $this->assertSame([500, 500, 200], $batches);
        $this->assertSame(1200, $response['accepted_count']);
    }

    public function test_send_job_updates_accepted_metrics_from_fcm_response(): void
    {
        $this->app->bind(FcmClientContract::class, static function () {
            return new class implements FcmClientContract
            {
                public function send(
                    PushMessage $message,
                    array $tokens,
                    string $messageInstanceId,
                    Carbon $expiresAt,
                    int $ttlMinutes
                ): array {
                    return [
                        'accepted_count' => 2,
                        'responses' => [
                            [
                                'token' => $tokens[0] ?? '',
                                'status' => 'accepted',
                                'provider_message_id' => 'msg-1',
                            ],
                            [
                                'token' => $tokens[1] ?? '',
                                'status' => 'accepted',
                                'provider_message_id' => 'msg-2',
                            ],
                        ],
                    ];
                }
            };
        });

        $this->app->bind(\Belluga\PushHandler\Services\PushRecipientResolver::class, static function () {
            return new class extends \Belluga\PushHandler\Services\PushRecipientResolver
            {
                public function __construct() {}

                public function countTargets(PushMessage $message, string $scope, ?string $accountId): int
                {
                    return 2;
                }

                public function streamResolvedTargetBatches(
                    PushMessage $message,
                    string $scope,
                    ?string $accountId,
                    int $batchSize,
                    callable $callback
                ): void {
                    $callback([
                        'tokens' => ['token-1', 'token-2'],
                        'token_user_map' => [
                            'token-1' => 'user-1',
                            'token-2' => 'user-2',
                        ],
                    ]);
                }

                public function resolveTokens(PushMessage $message, string $scope, ?string $accountId): array
                {
                    return ['token-1', 'token-2'];
                }

                public function resolveTokensWithUsers(PushMessage $message, string $scope, ?string $accountId): array
                {
                    return [
                        'tokens' => ['token-1', 'token-2'],
                        'token_user_map' => [
                            'token-1' => 'user-1',
                            'token-2' => 'user-2',
                        ],
                    ];
                }
            };
        });

        $message = PushMessage::create(array_replace($this->buildPayload(), [
            'scope' => 'account',
            'partner_id' => (string) $this->account->_id,
        ]));

        $job = new SendPushMessageJob((string) $message->_id, 'account', (string) $this->account->_id);
        $job->handle(
            $this->app->make(PushDeliveryService::class),
            $this->app->make(\Belluga\PushHandler\Services\PushRecipientResolver::class),
            $this->app->make(PushPlanPolicyContract::class),
            $this->app->make(\Belluga\PushHandler\Services\PushAudienceTopologyClassifier::class),
            $this->app->make(\Belluga\PushHandler\Contracts\PushChannelAuthorizationContract::class),
            $this->app->make(\Belluga\PushHandler\Contracts\PushChannelTargetResolverContract::class),
        );

        $message->refresh();
        $this->assertSame(2, $message->metrics['accepted_count'] ?? null);
        $this->assertSame(1, $message->metrics['sent_count'] ?? null);
        $this->assertSame('sent', $message->status);
        $this->assertNotNull($message->sent_at);
    }

    public function test_send_job_does_not_mark_direct_delivery_sent_when_every_provider_response_fails(): void
    {
        $this->app->bind(FcmClientContract::class, static function () {
            return new class implements FcmClientContract
            {
                public function send(
                    PushMessage $message,
                    array $tokens,
                    string $messageInstanceId,
                    Carbon $expiresAt,
                    int $ttlMinutes
                ): array {
                    return [
                        'accepted_count' => 0,
                        'responses' => array_map(static fn (string $token): array => [
                            'token' => $token,
                            'status' => 'failed',
                            'error_code' => 'UNAVAILABLE',
                            'error_message' => 'Provider unavailable.',
                        ], $tokens),
                    ];
                }
            };
        });

        $this->app->bind(\Belluga\PushHandler\Services\PushRecipientResolver::class, static function () {
            return new class extends \Belluga\PushHandler\Services\PushRecipientResolver
            {
                public function __construct() {}

                public function countTargets(PushMessage $message, string $scope, ?string $accountId): int
                {
                    return 2;
                }

                public function streamResolvedTargetBatches(
                    PushMessage $message,
                    string $scope,
                    ?string $accountId,
                    int $batchSize,
                    callable $callback
                ): void {
                    $callback([
                        'tokens' => ['token-1', 'token-2'],
                        'token_user_map' => [
                            'token-1' => 'user-1',
                            'token-2' => 'user-2',
                        ],
                    ]);
                }

                public function resolveTokens(PushMessage $message, string $scope, ?string $accountId): array
                {
                    return ['token-1', 'token-2'];
                }

                public function resolveTokensWithUsers(PushMessage $message, string $scope, ?string $accountId): array
                {
                    return [
                        'tokens' => ['token-1', 'token-2'],
                        'token_user_map' => [
                            'token-1' => 'user-1',
                            'token-2' => 'user-2',
                        ],
                    ];
                }
            };
        });

        $message = PushMessage::create(array_replace($this->buildPayload(), [
            'scope' => 'account',
            'partner_id' => (string) $this->account->_id,
        ]));

        $job = new SendPushMessageJob((string) $message->_id, 'account', (string) $this->account->_id);
        $job->handle(
            $this->app->make(PushDeliveryService::class),
            $this->app->make(\Belluga\PushHandler\Services\PushRecipientResolver::class),
            $this->app->make(PushPlanPolicyContract::class),
            $this->app->make(\Belluga\PushHandler\Services\PushAudienceTopologyClassifier::class),
            $this->app->make(\Belluga\PushHandler\Contracts\PushChannelAuthorizationContract::class),
            $this->app->make(\Belluga\PushHandler\Contracts\PushChannelTargetResolverContract::class),
        );

        $message->refresh();
        $this->assertSame(0, data_get($message->metrics ?? [], 'accepted_count', 0));
        $this->assertSame(0, data_get($message->metrics ?? [], 'sent_count', 0));
        $this->assertSame('failed', $message->status);
        $this->assertNull($message->sent_at);
        $this->assertSame('delivery_failed', data_get($message->delivery, 'last_terminal_state.reason'));
        $this->assertSame('individual_direct', data_get($message->delivery, 'last_terminal_state.context.delivery_topology'));
        $this->assertCount(2, PushDeliveryLog::query()->get());
    }

    public function test_send_job_marks_direct_delivery_failed_when_no_targets_resolve(): void
    {
        PushDeliveryLog::query()->delete();

        $this->app->bind(\Belluga\PushHandler\Services\PushRecipientResolver::class, static function () {
            return new class extends \Belluga\PushHandler\Services\PushRecipientResolver
            {
                public function __construct() {}

                public function countTargets(PushMessage $message, string $scope, ?string $accountId): int
                {
                    return 0;
                }

                public function streamResolvedTargetBatches(
                    PushMessage $message,
                    string $scope,
                    ?string $accountId,
                    int $batchSize,
                    callable $callback
                ): void {}

                public function resolveTokens(PushMessage $message, string $scope, ?string $accountId): array
                {
                    return [];
                }

                public function resolveTokensWithUsers(PushMessage $message, string $scope, ?string $accountId): array
                {
                    return [
                        'tokens' => [],
                        'token_user_map' => [],
                    ];
                }
            };
        });

        $message = PushMessage::create(array_replace($this->buildPayload(), [
            'scope' => 'account',
            'partner_id' => (string) $this->account->_id,
        ]));

        $job = new SendPushMessageJob((string) $message->_id, 'account', (string) $this->account->_id);
        $job->handle(
            $this->app->make(PushDeliveryService::class),
            $this->app->make(\Belluga\PushHandler\Services\PushRecipientResolver::class),
            $this->app->make(PushPlanPolicyContract::class),
            $this->app->make(\Belluga\PushHandler\Services\PushAudienceTopologyClassifier::class),
            $this->app->make(\Belluga\PushHandler\Contracts\PushChannelAuthorizationContract::class),
            $this->app->make(\Belluga\PushHandler\Contracts\PushChannelTargetResolverContract::class),
        );

        $message->refresh();
        $this->assertSame('failed', $message->status);
        $this->assertNull($message->sent_at);
        $this->assertSame('no_targets', data_get($message->delivery, 'last_terminal_state.reason'));
        $this->assertSame(0, data_get($message->delivery, 'last_terminal_state.context.requested_units'));
        $this->assertCount(0, PushDeliveryLog::query()->get());
    }

    public function test_send_job_preserves_sent_state_when_retry_lands_in_terminal_failure(): void
    {
        PushDeliveryLog::query()->delete();

        $this->app->bind(\Belluga\PushHandler\Services\PushRecipientResolver::class, static function () {
            return new class extends \Belluga\PushHandler\Services\PushRecipientResolver
            {
                public function __construct() {}

                public function countTargets(PushMessage $message, string $scope, ?string $accountId): int
                {
                    return 0;
                }

                public function streamResolvedTargetBatches(
                    PushMessage $message,
                    string $scope,
                    ?string $accountId,
                    int $batchSize,
                    callable $callback
                ): void {}

                public function resolveTokens(PushMessage $message, string $scope, ?string $accountId): array
                {
                    return [];
                }

                public function resolveTokensWithUsers(PushMessage $message, string $scope, ?string $accountId): array
                {
                    return [
                        'tokens' => [],
                        'token_user_map' => [],
                    ];
                }
            };
        });

        $sentAt = Carbon::parse('2026-05-20T19:00:00Z');

        $message = PushMessage::create(array_replace($this->buildPayload(), [
            'scope' => 'account',
            'partner_id' => (string) $this->account->_id,
            'status' => 'sent',
            'sent_at' => $sentAt,
            'metrics' => [
                'accepted_count' => 1,
                'sent_count' => 1,
            ],
        ]));

        $job = new SendPushMessageJob((string) $message->_id, 'account', (string) $this->account->_id);
        $job->handle(
            $this->app->make(PushDeliveryService::class),
            $this->app->make(\Belluga\PushHandler\Services\PushRecipientResolver::class),
            $this->app->make(PushPlanPolicyContract::class),
            $this->app->make(\Belluga\PushHandler\Services\PushAudienceTopologyClassifier::class),
            $this->app->make(\Belluga\PushHandler\Contracts\PushChannelAuthorizationContract::class),
            $this->app->make(\Belluga\PushHandler\Contracts\PushChannelTargetResolverContract::class),
        );

        $message->refresh();
        $this->assertSame('sent', $message->status);
        $this->assertNotNull($message->sent_at);
        $this->assertSame($sentAt->toISOString(), $message->sent_at?->toISOString());
        $this->assertNull(data_get($message->delivery, 'last_terminal_state'));
        $this->assertSame(1, data_get($message->metrics, 'accepted_count'));
        $this->assertSame(1, data_get($message->metrics, 'sent_count'));
        $this->assertCount(0, PushDeliveryLog::query()->get());
    }

    public function test_send_job_invalidates_not_found_tokens_for_async_direct_delivery(): void
    {
        $secondaryUser = $this->userService->create($this->account, [
            'name' => 'Push Secondary Recipient',
            'email' => 'push-secondary@example.org',
            'password' => 'Secret!234',
        ], (string) $this->operatorRole->_id);

        $this->seedPushDevice($this->operator, [
            'device_id' => 'device-1',
            'platform' => 'android',
            'push_token' => 'token-1',
        ]);
        $this->seedPushDevice($secondaryUser, [
            'device_id' => 'device-2',
            'platform' => 'android',
            'push_token' => 'token-2',
        ]);

        $this->app->bind(FcmClientContract::class, static function () {
            return new class implements FcmClientContract
            {
                public function send(
                    PushMessage $message,
                    array $tokens,
                    string $messageInstanceId,
                    Carbon $expiresAt,
                    int $ttlMinutes
                ): array {
                    return [
                        'accepted_count' => 0,
                        'responses' => [
                            [
                                'token' => 'token-1',
                                'status' => 'failed',
                                'error_code' => 'NOT_FOUND',
                                'error_message' => 'Requested entity was not found.',
                            ],
                            [
                                'token' => 'token-2',
                                'status' => 'failed',
                                'error_code' => 'UNAVAILABLE',
                                'error_message' => 'Provider unavailable.',
                            ],
                        ],
                    ];
                }
            };
        });

        $operatorUserId = (string) $this->operator->_id;
        $secondaryUserId = (string) $secondaryUser->_id;
        $this->app->bind(\Belluga\PushHandler\Services\PushRecipientResolver::class, static function () use ($operatorUserId, $secondaryUserId) {
            return new class($operatorUserId, $secondaryUserId) extends \Belluga\PushHandler\Services\PushRecipientResolver
            {
                public function __construct(
                    private readonly string $operatorUserId,
                    private readonly string $secondaryUserId,
                ) {}

                public function countTargets(PushMessage $message, string $scope, ?string $accountId): int
                {
                    return 2;
                }

                public function streamResolvedTargetBatches(
                    PushMessage $message,
                    string $scope,
                    ?string $accountId,
                    int $batchSize,
                    callable $callback
                ): void {
                    $callback([
                        'tokens' => ['token-1', 'token-2'],
                        'token_user_map' => [
                            'token-1' => $this->operatorUserId,
                            'token-2' => $this->secondaryUserId,
                        ],
                    ]);
                }

                public function resolveTokens(PushMessage $message, string $scope, ?string $accountId): array
                {
                    return ['token-1', 'token-2'];
                }

                public function resolveTokensWithUsers(PushMessage $message, string $scope, ?string $accountId): array
                {
                    return [
                        'tokens' => ['token-1', 'token-2'],
                        'token_user_map' => [
                            'token-1' => $this->operatorUserId,
                            'token-2' => $this->secondaryUserId,
                        ],
                    ];
                }
            };
        });

        $message = PushMessage::create(array_replace($this->buildPayload(), [
            'scope' => 'account',
            'partner_id' => (string) $this->account->_id,
        ]));

        $job = new SendPushMessageJob((string) $message->_id, 'account', (string) $this->account->_id);
        $job->handle(
            $this->app->make(PushDeliveryService::class),
            $this->app->make(\Belluga\PushHandler\Services\PushRecipientResolver::class),
            $this->app->make(PushPlanPolicyContract::class),
            $this->app->make(\Belluga\PushHandler\Services\PushAudienceTopologyClassifier::class),
            $this->app->make(\Belluga\PushHandler\Contracts\PushChannelAuthorizationContract::class),
            $this->app->make(\Belluga\PushHandler\Contracts\PushChannelTargetResolverContract::class),
            $this->app->make(\Belluga\PushHandler\Contracts\PushUserGatewayContract::class),
            $this->app->make(PushDeviceService::class),
        );

        $message->refresh();
        $this->assertSame('failed', $message->status);
        $this->assertSame('delivery_failed', data_get($message->delivery, 'last_terminal_state.reason'));

        $invalidatedDevice = PushDevice::query()
            ->where('account_user_id', $operatorUserId)
            ->where('device_id', 'device-1')
            ->firstOrFail();
        $healthyDevice = PushDevice::query()
            ->where('account_user_id', $secondaryUserId)
            ->where('device_id', 'device-2')
            ->firstOrFail();

        $this->assertFalse((bool) $invalidatedDevice->is_active);
        $this->assertNotNull($invalidatedDevice->invalidated_at);
        $this->assertTrue((bool) $healthyDevice->is_active);
    }

    public function test_send_job_delivers_shared_all_users_audience_via_topic(): void
    {
        $topics = [];
        $this->bindTopicOnlyTransportSpy($topics);

        PushDeliveryLog::query()->delete();

        $message = PushMessage::create(array_replace($this->buildPayload([
            'audience' => [
                'type' => 'all_users',
            ],
        ]), [
            'scope' => 'tenant',
        ]));

        $job = new SendPushMessageJob((string) $message->_id, 'tenant', null);
        $job->handle(
            $this->app->make(PushDeliveryService::class),
            $this->app->make(PushRecipientResolver::class),
            $this->app->make(PushPlanPolicyContract::class),
            $this->app->make(\Belluga\PushHandler\Services\PushAudienceTopologyClassifier::class),
            $this->app->make(\Belluga\PushHandler\Contracts\PushChannelAuthorizationContract::class),
            $this->app->make(\Belluga\PushHandler\Contracts\PushChannelTargetResolverContract::class),
        );

        $message->refresh();

        $this->assertCount(1, $topics);
        $this->assertSame(
            $this->app->make(\App\Application\Push\PushChannelNamingService::class)->allUsersTopic(),
            $topics[0]
        );
        $this->assertSame(1, $message->metrics['accepted_count'] ?? null);
        $this->assertSame(1, data_get($message->metrics, 'delivery_topology_counts.channel_topic'));
        $this->assertSame('channel_topic', data_get($message->metrics, 'last_delivery_topology'));

        $log = PushDeliveryLog::query()->firstOrFail();
        $this->assertSame('channel_topic', (string) $log->delivery_topology);
        $this->assertSame('topic', (string) $log->target_type);
    }

    public function test_send_job_delivers_shared_favorite_account_profile_audience_via_topic_only(): void
    {
        $topics = [];
        $this->bindTopicOnlyTransportSpy($topics);

        PushDeliveryLog::query()->delete();

        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'artist',
            'display_name' => 'Topic Favorite Profile',
            'is_active' => true,
        ]);

        $message = PushMessage::create(array_replace($this->buildPayload([
            'audience' => [
                'type' => 'favorite_account_profile',
                'account_profile_id' => (string) $profile->_id,
            ],
        ]), [
            'scope' => 'account',
            'partner_id' => (string) $this->account->_id,
        ]));

        $job = new SendPushMessageJob((string) $message->_id, 'account', (string) $this->account->_id);
        $job->handle(
            $this->app->make(PushDeliveryService::class),
            $this->app->make(PushRecipientResolver::class),
            $this->app->make(PushPlanPolicyContract::class),
            $this->app->make(\Belluga\PushHandler\Services\PushAudienceTopologyClassifier::class),
            $this->app->make(\Belluga\PushHandler\Contracts\PushChannelAuthorizationContract::class),
            $this->app->make(\Belluga\PushHandler\Contracts\PushChannelTargetResolverContract::class),
        );

        $message->refresh();

        $this->assertSame([
            $this->app->make(PushChannelNamingService::class)->favoriteAccountProfileTopic((string) $profile->_id),
        ], $topics);
        $this->assertSame(1, data_get($message->metrics, 'delivery_topology_counts.channel_topic'));
        $this->assertSame('channel_topic', data_get($message->metrics, 'last_delivery_topology'));

        $log = PushDeliveryLog::query()->firstOrFail();
        $this->assertSame('channel_topic', (string) $log->delivery_topology);
        $this->assertSame('topic', (string) $log->target_type);
    }

    public function test_send_job_delivers_shared_event_confirmed_audience_via_topic_only(): void
    {
        $topics = [];
        $this->bindTopicOnlyTransportSpy($topics);

        PushDeliveryLog::query()->delete();

        $event = Event::query()->create([
            'title' => 'Topic Confirmed Event',
            'account_context_ids' => [(string) $this->account->_id],
        ]);

        $message = PushMessage::create(array_replace($this->buildPayload([
            'audience' => [
                'type' => 'event_confirmed',
                'event_id' => (string) $event->_id,
            ],
        ]), [
            'scope' => 'account',
            'partner_id' => (string) $this->account->_id,
        ]));

        $job = new SendPushMessageJob((string) $message->_id, 'account', (string) $this->account->_id);
        $job->handle(
            $this->app->make(PushDeliveryService::class),
            $this->app->make(PushRecipientResolver::class),
            $this->app->make(PushPlanPolicyContract::class),
            $this->app->make(\Belluga\PushHandler\Services\PushAudienceTopologyClassifier::class),
            $this->app->make(\Belluga\PushHandler\Contracts\PushChannelAuthorizationContract::class),
            $this->app->make(\Belluga\PushHandler\Contracts\PushChannelTargetResolverContract::class),
        );

        $message->refresh();

        $this->assertSame([
            $this->app->make(PushChannelNamingService::class)->confirmedEventTopic((string) $event->_id),
        ], $topics);
        $this->assertSame(1, data_get($message->metrics, 'delivery_topology_counts.channel_topic'));
        $this->assertSame('channel_topic', data_get($message->metrics, 'last_delivery_topology'));

        $log = PushDeliveryLog::query()->firstOrFail();
        $this->assertSame('channel_topic', (string) $log->delivery_topology);
        $this->assertSame('topic', (string) $log->target_type);
    }

    public function test_send_job_does_not_mark_topic_delivery_sent_when_provider_accepts_none(): void
    {
        $topics = [];
        $this->bindTopicOnlyTransportSpy($topics, acceptedCount: 0, status: 'failed');

        $message = PushMessage::create(array_replace($this->buildPayload([
            'audience' => [
                'type' => 'all_users',
            ],
        ]), [
            'scope' => 'tenant',
        ]));

        $job = new SendPushMessageJob((string) $message->_id, 'tenant', null);
        $job->handle(
            $this->app->make(PushDeliveryService::class),
            $this->app->make(PushRecipientResolver::class),
            $this->app->make(PushPlanPolicyContract::class),
            $this->app->make(\Belluga\PushHandler\Services\PushAudienceTopologyClassifier::class),
            $this->app->make(\Belluga\PushHandler\Contracts\PushChannelAuthorizationContract::class),
            $this->app->make(\Belluga\PushHandler\Contracts\PushChannelTargetResolverContract::class),
        );

        $message->refresh();
        $this->assertSame([
            $this->app->make(\App\Application\Push\PushChannelNamingService::class)->allUsersTopic(),
        ], $topics);
        $this->assertSame(0, data_get($message->metrics ?? [], 'accepted_count', 0));
        $this->assertSame(0, data_get($message->metrics ?? [], 'sent_count', 0));
        $this->assertSame('failed', $message->status);
        $this->assertNull($message->sent_at);
        $this->assertSame('delivery_failed', data_get($message->delivery, 'last_terminal_state.reason'));
        $this->assertSame('channel_topic', data_get($message->delivery, 'last_terminal_state.context.delivery_topology'));
    }

    public function test_fcm_http_client_builds_payload_with_overrides(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        PushCredential::query()->delete();
        $keyResource = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        $privateKey = '';
        openssl_pkey_export($keyResource, $privateKey);

        PushCredential::create([
            'project_id' => 'project-id',
            'client_email' => 'client@example.org',
            'private_key' => $privateKey,
        ]);
        Cache::flush();

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'token'], 200),
            'https://fcm.googleapis.com/v1/projects/project-id/messages:send' => static function ($request) {
                $token = data_get($request->data(), 'message.token', 'missing-token');

                return Http::response(['name' => 'msg-'.$token], 200);
            },
            'https://fcm.googleapis.com/batch' => Http::response(['error' => 'batch endpoint forbidden'], 418),
        ]);

        $message = PushMessage::create($this->buildPayload([
            'fcm_options' => [
                'notification' => [
                    'title' => 'Override title',
                    'body' => 'Override body',
                ],
                'data' => [
                    'custom' => 'value',
                ],
            ],
        ]));

        $expiresAt = Carbon::now()->addMinutes(10);
        $client = $this->app->make(FcmHttpV1Client::class);
        $client->send($message, ['token-1', 'token-2'], 'instance-1', $expiresAt, 10);

        Http::assertSentCount(3);
        Http::assertNotSent(fn ($request) => $request->url() === 'https://fcm.googleapis.com/batch');
        foreach (['token-1', 'token-2'] as $token) {
            Http::assertSent(function ($request) use ($expiresAt, $token) {
                if ($request->url() !== 'https://fcm.googleapis.com/v1/projects/project-id/messages:send') {
                    return false;
                }
                $body = $request->body();
                if (! is_string($body)) {
                    return false;
                }

                return str_contains($body, '"token":"'.$token.'"')
                    && str_contains($body, '"title":"Override title"')
                    && str_contains($body, '"custom":"value"')
                    && str_contains($body, '"ttl":"600s"')
                    && str_contains($body, '"TTL":"600"')
                    && str_contains($body, '"apns-expiration":"'.(string) $expiresAt->getTimestamp().'"')
                    && ! str_contains($body, 'multipart/mixed')
                    && ! str_contains($body, 'POST /v1/projects/project-id/messages:send HTTP/1.1');
            });
        }

        Carbon::setTestNow();
    }

    public function test_fcm_http_client_honors_platform_overrides(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        PushCredential::query()->delete();
        $keyResource = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        $privateKey = '';
        openssl_pkey_export($keyResource, $privateKey);

        PushCredential::create([
            'project_id' => 'project-id',
            'client_email' => 'client@example.org',
            'private_key' => $privateKey,
        ]);
        Cache::flush();

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'token'], 200),
            'https://fcm.googleapis.com/v1/projects/project-id/messages:send' => Http::response(['name' => 'msg-token-1'], 200),
            'https://fcm.googleapis.com/batch' => Http::response(['error' => 'batch endpoint forbidden'], 418),
        ]);

        $message = PushMessage::create($this->buildPayload([
            'title_template' => 'Default title',
            'body_template' => 'Default body',
            'fcm_options' => [
                'android' => [
                    'notification' => [
                        'title' => 'Android title',
                    ],
                ],
                'apns' => [
                    'payload' => [
                        'aps' => [
                            'alert' => [
                                'title' => 'Apns title',
                                'body' => 'Apns body',
                            ],
                        ],
                    ],
                ],
            ],
        ]));

        $expiresAt = Carbon::now()->addMinutes(15);
        $client = $this->app->make(FcmHttpV1Client::class);
        $client->send($message, ['token-1'], 'instance-2', $expiresAt, 15);

        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->url() === 'https://fcm.googleapis.com/batch');
        Http::assertSent(function ($request) use ($expiresAt) {
            if ($request->url() !== 'https://fcm.googleapis.com/v1/projects/project-id/messages:send') {
                return false;
            }

            $body = $request->body();
            if (! is_string($body)) {
                return false;
            }

            return str_contains($body, '"token":"token-1"')
                && str_contains($body, '"title":"Default title"')
                && str_contains($body, '"body":"Default body"')
                && str_contains($body, '"notification":{"title":"Android title"}')
                && str_contains($body, '"alert":{"title":"Apns title","body":"Apns body"}')
                && str_contains($body, '"ttl":"900s"')
                && str_contains($body, '"TTL":"900"')
                && str_contains($body, '"apns-expiration":"'.(string) $expiresAt->getTimestamp().'"')
                && ! str_contains($body, 'multipart/mixed')
                && ! str_contains($body, 'POST /v1/projects/project-id/messages:send HTTP/1.1');
        });

        Carbon::setTestNow();
    }

    public function test_fcm_http_client_sends_each_recipient_to_supported_http_v1_endpoint(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        PushCredential::query()->delete();
        $keyResource = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        $privateKey = '';
        openssl_pkey_export($keyResource, $privateKey);

        PushCredential::create([
            'project_id' => 'project-id',
            'client_email' => 'client@example.org',
            'private_key' => $privateKey,
        ]);
        Cache::flush();

        $tokens = ['token-0', 'token-1', 'token-2'];

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'token'], 200),
            'https://fcm.googleapis.com/v1/projects/project-id/messages:send' => static function ($request) {
                $token = data_get($request->data(), 'message.token', 'missing-token');

                return Http::response(['name' => 'projects/project-id/messages/msg-'.$token], 200);
            },
            'https://fcm.googleapis.com/batch' => Http::response(['error' => 'batch endpoint forbidden'], 418),
        ]);

        $message = PushMessage::create($this->buildPayload());

        $expiresAt = Carbon::now()->addMinutes(10);
        $client = $this->app->make(FcmHttpV1Client::class);
        $result = $client->send($message, $tokens, 'instance-budget', $expiresAt, 10);

        $this->assertSame(3, $result['accepted_count']);
        $this->assertCount(3, $result['responses']);
        $this->assertSame('accepted', $result['responses'][0]['status'] ?? null);

        Http::assertSentCount(4);
        Http::assertNotSent(fn ($request) => $request->url() === 'https://fcm.googleapis.com/batch');
        foreach ($tokens as $token) {
            Http::assertSent(fn ($request) => $request->url() === 'https://fcm.googleapis.com/v1/projects/project-id/messages:send'
                && data_get($request->data(), 'message.token') === $token);
        }

        Carbon::setTestNow();
    }

    public function test_fcm_http_client_sends_topic_to_supported_http_v1_endpoint(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        PushCredential::query()->delete();
        $keyResource = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        $privateKey = '';
        openssl_pkey_export($keyResource, $privateKey);

        PushCredential::create([
            'project_id' => 'project-id',
            'client_email' => 'client@example.org',
            'private_key' => $privateKey,
        ]);
        Cache::flush();

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'token'], 200),
            'https://fcm.googleapis.com/v1/projects/project-id/messages:send' => Http::response(['name' => 'projects/project-id/messages/topic-all-users'], 200),
            'https://fcm.googleapis.com/batch' => Http::response(['error' => 'batch endpoint forbidden'], 418),
        ]);

        $message = PushMessage::create($this->buildPayload([
            'fcm_options' => [
                'data' => [
                    'custom' => 'topic-value',
                ],
            ],
        ]));

        $expiresAt = Carbon::now()->addMinutes(12);
        $client = $this->app->make(FcmHttpV1Client::class);
        $result = $client->sendTopic($message, 'all-users', 'instance-topic', $expiresAt, 12);

        $this->assertSame(1, $result['accepted_count']);
        $this->assertCount(1, $result['responses']);
        $this->assertSame('accepted', $result['responses'][0]['status'] ?? null);

        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->url() === 'https://fcm.googleapis.com/batch');
        Http::assertSent(function ($request) use ($expiresAt) {
            if ($request->url() !== 'https://fcm.googleapis.com/v1/projects/project-id/messages:send') {
                return false;
            }

            $body = $request->body();
            if (! is_string($body)) {
                return false;
            }

            return str_contains($body, '"topic":"all-users"')
                && ! str_contains($body, '"token":')
                && str_contains($body, '"custom":"topic-value"')
                && str_contains($body, '"ttl":"720s"')
                && str_contains($body, '"TTL":"720"')
                && str_contains($body, '"apns-expiration":"'.(string) $expiresAt->getTimestamp().'"')
                && ! str_contains($body, 'multipart/mixed')
                && ! str_contains($body, 'POST /v1/projects/project-id/messages:send HTTP/1.1');
        });

        Carbon::setTestNow();
    }

    public function test_single_direct_user_within_batch_limit_uses_single_push_device_query(): void
    {
        $user = $this->userService->create($this->account, [
            'name' => 'Query Budget User',
            'email' => 'query-budget-user@example.org',
            'password' => 'Secret!234',
        ], (string) $this->operatorRole->_id);

        for ($index = 0; $index < 205; $index++) {
            $this->seedPushDevice($user, [
                'device_id' => sprintf('query-budget-device-%03d', $index),
                'platform' => 'android',
                'push_token' => sprintf('query-budget-token-%03d', $index),
                'is_active' => true,
            ]);
        }

        $resolver = $this->app->make(PushRecipientResolver::class);
        $message = new PushMessage([
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $user->_id],
            ],
        ]);

        $queryCount = $this->countTenantQueries(function () use ($resolver, $message): void {
            $resolver->resolveTokensWithUsers($message, 'account', (string) $this->account->_id);
        });

        $this->assertSame(1, $queryCount);
    }

    public function test_single_direct_user_above_batch_limit_uses_one_push_device_query_per_batch_window(): void
    {
        $user = $this->userService->create($this->account, [
            'name' => 'Query Budget Spill User',
            'email' => 'query-budget-spill-user@example.org',
            'password' => 'Secret!234',
        ], (string) $this->operatorRole->_id);

        for ($index = 0; $index < 501; $index++) {
            $this->seedPushDevice($user, [
                'device_id' => sprintf('query-budget-spill-device-%03d', $index),
                'platform' => 'android',
                'push_token' => sprintf('query-budget-spill-token-%03d', $index),
                'is_active' => true,
            ]);
        }

        $resolver = $this->app->make(PushRecipientResolver::class);
        $message = new PushMessage([
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $user->_id],
            ],
        ]);

        $queryCount = $this->countTenantQueries(function () use ($resolver, $message): void {
            $resolver->resolveTokensWithUsers($message, 'account', (string) $this->account->_id);
        });

        $this->assertSame(2, $queryCount);
    }

    public function test_quota_check_blocked_returns_reason(): void
    {
        $this->app->bind(PushPlanPolicyContract::class, static function () {
            return new class implements PushPlanPolicyContract, PushPlanPolicyDecisionContract
            {
                public function canSend(string $accountId, PushMessage $message, int $requestedUnits): bool
                {
                    return false;
                }

                public function quotaDecision(string $accountId, PushMessage $message, int $requestedUnits): array
                {
                    return [
                        'allowed' => false,
                        'limit' => 10,
                        'current_used' => 10,
                        'requested' => $requestedUnits,
                        'remaining_after' => 0,
                        'period' => 'monthly',
                        'reason' => 'quota_exceeded',
                    ];
                }
            };
        });

        Sanctum::actingAs($this->operator, ['push-messages:send']);

        $profile = AccountProfile::query()->create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'artist',
            'display_name' => 'Blocked Quota Favorite Profile',
            'is_active' => true,
        ]);

        $response = $this->getJson(sprintf(
            'api/v1/accounts/%s/push/quota-check?audience[type]=favorite_account_profile&audience[account_profile_id]=%s',
            $this->account->slug,
            (string) $profile->_id
        ));

        $response->assertOk();
        $response->assertJsonPath('allowed', false);
        $response->assertJsonPath('reason', 'quota_exceeded');
    }

    public function test_transactional_send_uses_persisted_direct_recipient_without_request_target(): void
    {
        $this->actingAsOperator();

        $foreignAccount = Account::query()->create([
            'name' => 'Cross Scope Push Account',
            'document' => 'DOC-CROSS-SCOPE-PUSH',
        ]);

        $sendCalls = [];
        $this->app->bind(FcmClientContract::class, function () use (&$sendCalls) {
            return new class($sendCalls) implements FcmClientContract
            {
                /**
                 * @param  array<int, array{tokens:array<int,string>,message_id:string}>  $sendCalls
                 */
                public function __construct(private array &$sendCalls) {}

                public function send(
                    PushMessage $message,
                    array $tokens,
                    string $messageInstanceId,
                    Carbon $expiresAt,
                    int $ttlMinutes
                ): array {
                    $this->sendCalls[] = [
                        'tokens' => array_values($tokens),
                        'message_id' => (string) $message->_id,
                    ];

                    return [
                        'accepted_count' => count($tokens),
                        'message_instance_id' => $messageInstanceId,
                        'responses' => array_map(static fn (string $token): array => [
                            'token' => $token,
                            'status' => 'accepted',
                            'provider_message_id' => 'txn-'.$token,
                        ], $tokens),
                    ];
                }
            };
        });
        PushDeliveryLog::query()->delete();

        $payload = $this->buildPayload([
            'type' => 'transactional',
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $this->operator->_id],
            ],
        ]);

        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $this->seedPushDevice($this->operator, [
            'device_id' => 'device-1',
            'push_token' => 'token-1',
        ]);
        $this->seedPushDevice($this->operator, [
            'device_id' => 'foreign-account-device',
            'push_token' => 'foreign-account-token',
            'account_ids' => [(string) $foreignAccount->_id],
        ]);

        $send = $this->postJson($this->baseUrl.'/'.$messageId.'/send', []);

        $send->assertOk();
        $send->assertJsonPath('ok', true);
        $send->assertJsonPath('recipient_user_id', (string) $this->operator->_id);
        $send->assertJsonPath('delivery_topology', 'individual_direct');
        $send->assertJsonPath('delivery_status', 'accepted');
        $send->assertJsonMissingPath('queued');

        $this->assertSame([
            [
                'tokens' => ['token-1'],
                'message_id' => $messageId,
            ],
        ], $sendCalls);

        $log = PushDeliveryLog::query()->firstOrFail();
        $this->assertSame('individual_direct', (string) $log->delivery_topology);
        $this->assertSame('token', (string) $log->target_type);
    }

    public function test_transactional_send_honors_device_filter_for_persisted_direct_recipient(): void
    {
        $this->actingAsOperator();

        $foreignAccount = Account::query()->create([
            'name' => 'Device Filter Foreign Account',
            'document' => 'DOC-DEVICE-FILTER-PUSH',
        ]);

        $sendCalls = [];
        $this->app->bind(FcmClientContract::class, function () use (&$sendCalls) {
            return new class($sendCalls) implements FcmClientContract
            {
                /**
                 * @param  array<int, array{tokens:array<int,string>,message_id:string}>  $sendCalls
                 */
                public function __construct(private array &$sendCalls) {}

                public function send(
                    PushMessage $message,
                    array $tokens,
                    string $messageInstanceId,
                    Carbon $expiresAt,
                    int $ttlMinutes
                ): array {
                    $this->sendCalls[] = [
                        'tokens' => array_values($tokens),
                        'message_id' => (string) $message->_id,
                    ];

                    return [
                        'accepted_count' => count($tokens),
                        'message_instance_id' => $messageInstanceId,
                        'responses' => array_map(static fn (string $token): array => [
                            'token' => $token,
                            'status' => 'accepted',
                            'provider_message_id' => 'txn-'.$token,
                        ], $tokens),
                    ];
                }
            };
        });

        $payload = $this->buildPayload([
            'type' => 'transactional',
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $this->operator->_id],
            ],
        ]);

        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $this->seedPushDevice($this->operator, [
            'device_id' => 'device-1',
            'push_token' => 'token-1',
        ]);
        $this->seedPushDevice($this->operator, [
            'device_id' => 'foreign-device',
            'push_token' => 'foreign-token',
            'account_ids' => [(string) $foreignAccount->_id],
        ]);

        $foreignSend = $this->postJson($this->baseUrl.'/'.$messageId.'/send', [
            'device_id' => 'foreign-device',
        ]);
        $foreignSend->assertStatus(422);
        $foreignSend->assertJsonPath('reason', 'no_tokens');

        $send = $this->postJson($this->baseUrl.'/'.$messageId.'/send', [
            'device_id' => 'device-1',
        ]);
        $send->assertOk();
        $send->assertJsonPath('ok', true);
        $send->assertJsonPath('delivery_topology', 'individual_direct');
        $send->assertJsonPath('delivery_status', 'accepted');
        $send->assertJsonMissingPath('queued');
        $this->assertSame([
            [
                'tokens' => ['token-1'],
                'message_id' => $messageId,
            ],
        ], $sendCalls);
    }

    public function test_transactional_send_rejects_legacy_user_id_and_email_chooser_inputs(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'type' => 'transactional',
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $this->operator->_id],
            ],
        ]);

        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $send = $this->postJson($this->baseUrl.'/'.$messageId.'/send', [
            'dry_run' => true,
            'user_id' => Str::uuid()->toString(),
            'email' => 'legacy-send@example.org',
        ]);

        $send->assertStatus(422);
        $send->assertJsonValidationErrors([
            'user_id',
            'email',
        ]);
    }

    public function test_register_updates_device_token_and_reactivates(): void
    {
        $user = AccountUser::query()->where('_id', $this->operator->_id)->firstOrFail();
        $this->seedPushDevice($user, [
            'device_id' => 'device-1',
            'platform' => 'android',
            'push_token' => 'token-old',
            'is_active' => false,
            'invalidated_at' => new UTCDateTime,
        ]);

        $service = $this->app->make(PushDeviceService::class);
        $service->register($user, [
            'device_id' => 'device-1',
            'platform' => 'android',
            'push_token' => 'token-new',
        ]);

        $device = PushDevice::query()
            ->where('account_user_id', (string) $user->_id)
            ->where('device_id', 'device-1')
            ->firstOrFail();
        $this->assertSame('token-new', $device->push_token);
        $this->assertTrue((bool) $device->is_active);
        $this->assertNull($device->invalidated_at);
    }

    public function test_invalidate_tokens_marks_inactive_and_keeps_others(): void
    {
        $user = AccountUser::query()->where('_id', $this->operator->_id)->firstOrFail();
        $this->seedPushDevices($user, [
            [
                'device_id' => 'device-1',
                'platform' => 'android',
                'push_token' => 'token-1',
            ],
            [
                'device_id' => 'device-2',
                'platform' => 'ios',
                'push_token' => 'token-2',
            ],
        ]);

        $service = $this->app->make(PushDeviceService::class);
        $service->invalidateTokens($user, ['token-1']);

        $device1 = PushDevice::query()
            ->where('account_user_id', (string) $user->_id)
            ->where('device_id', 'device-1')
            ->firstOrFail();
        $device2 = PushDevice::query()
            ->where('account_user_id', (string) $user->_id)
            ->where('device_id', 'device-2')
            ->firstOrFail();

        $this->assertFalse((bool) $device1->is_active);
        $this->assertNotNull($device1->invalidated_at);
        $this->assertTrue((bool) $device2->is_active);
    }

    public function test_push_topics_repair_command_reprojects_active_tokens(): void
    {
        TenantPushSettings::query()->delete();
        TenantPushSettings::create($this->buildTenantSettingsPayload([
            'push' => [
                'enabled' => true,
            ],
        ]));

        $transport = new \Tests\Fakes\FakePushTopicTransport();
        $this->app->instance(\Belluga\PushHandler\Contracts\PushTopicTransportContract::class, $transport);

        $user = AccountUser::query()->where('_id', $this->operator->_id)->firstOrFail();
        $this->seedPushDevices($user, [
            [
                'device_id' => 'repair-active-device',
                'platform' => 'android',
                'push_token' => 'repair-active-token',
                'is_active' => true,
            ],
            [
                'device_id' => 'repair-inactive-device',
                'platform' => 'ios',
                'push_token' => 'repair-inactive-token',
                'is_active' => false,
                'invalidated_at' => new UTCDateTime,
            ],
        ]);

        $exitCode = Artisan::call('push:topics:repair', [
            'tenant_slug' => 'tenant-zeta',
        ]);

        $this->assertSame(0, $exitCode);
        $this->resolvePrimaryPushTenant()->makeCurrent();
        $this->assertSame([['repair-active-token']], $transport->unsubscribeAll);
        $this->assertContains([
            'topic' => $this->app->make(PushChannelNamingService::class)->allUsersTopic(),
            'tokens' => ['repair-active-token'],
        ], $transport->subscriptions);
        $this->assertNotContains(['repair-inactive-token'], $transport->unsubscribeAll);
    }

    public function test_push_topics_repair_groups_tokens_by_user_per_chunk(): void
    {
        $tenant = $this->resolvePrimaryPushTenant();
        $tenant->makeCurrent();

        $user = AccountUser::query()->where('_id', $this->operator->_id)->firstOrFail();
        $this->seedPushDevices($user, [
            [
                'device_id' => 'repair-group-device-1',
                'platform' => 'android',
                'push_token' => 'repair-group-token-1',
                'is_active' => true,
            ],
            [
                'device_id' => 'repair-group-device-2',
                'platform' => 'ios',
                'push_token' => 'repair-group-token-2',
                'is_active' => true,
            ],
        ]);

        $memberships = $this->getMockBuilder(PushTopicMembershipService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['syncTokensForUser'])
            ->getMock();
        $memberships->expects($this->once())
            ->method('syncTokensForUser')
            ->with(
                (string) $user->_id,
                $this->callback(static function (array $tokens): bool {
                    sort($tokens);

                    return $tokens === ['repair-group-token-1', 'repair-group-token-2'];
                })
            );

        $this->app->instance(PushTopicMembershipService::class, $memberships);

        $exitCode = Artisan::call('push:topics:repair', [
            'tenant_slug' => (string) $tenant->slug,
            '--chunk' => 200,
        ]);

        $this->assertSame(0, $exitCode);
    }

    public function test_recipient_resolver_skips_inactive_tokens(): void
    {
        $user = AccountUser::query()->where('_id', $this->operator->_id)->firstOrFail();
        $this->seedPushDevices($user, [
            [
                'device_id' => 'device-1',
                'platform' => 'android',
                'push_token' => 'token-active',
                'is_active' => true,
            ],
            [
                'device_id' => 'device-2',
                'platform' => 'ios',
                'push_token' => 'token-inactive',
                'is_active' => false,
            ],
        ]);

        $resolver = $this->app->make(PushRecipientResolver::class);
        $tokens = $resolver->tokensForUser($user);

        $this->assertSame(['token-active'], $tokens);
    }

    public function test_recipient_resolver_queries_explicit_user_audience_by_ids_without_tenant_scan(): void
    {
        $target = AccountUser::query()->where('_id', $this->operator->_id)->firstOrFail();
        $this->seedPushDevice($target, [
            'device_id' => 'target-device',
            'platform' => 'android',
            'push_token' => 'target-token',
            'is_active' => true,
        ]);

        $other = $this->userService->create($this->account, [
            'name' => 'Other Push User',
            'email' => 'other-push-user@example.org',
            'password' => 'Secret!234',
        ], (string) $this->operatorRole->_id);
        $this->seedPushDevice($other, [
            'device_id' => 'other-device',
            'platform' => 'android',
            'push_token' => 'other-token',
            'is_active' => true,
        ]);

        $gateway = new class extends PushUserGatewayAdapter
        {
            public int $chunkAllTargetsCalls = 0;

            public int $chunkTargetsByUserIdsCalls = 0;

            /** @var array<int, string> */
            public array $seenUserIds = [];

            /** @var array<int, int> */
            public array $seenBatchSizes = [];

            public function chunkActivePushTargetsByUserIds(?string $accountId, array $userIds, int $chunkSize, callable $callback): void
            {
                $this->chunkTargetsByUserIdsCalls++;
                $this->seenUserIds = array_values($userIds);
                $this->seenBatchSizes[] = $chunkSize;

                parent::chunkActivePushTargetsByUserIds($accountId, $userIds, $chunkSize, $callback);
            }

            public function chunkActivePushTargets(?string $accountId, int $chunkSize, callable $callback): void
            {
                $this->chunkAllTargetsCalls++;

                parent::chunkActivePushTargets($accountId, $chunkSize, $callback);
            }
        };

        $resolver = new PushRecipientResolver(
            $gateway,
            $this->app->make(\Belluga\PushHandler\Services\PushAudienceTopologyClassifier::class),
        );

        $message = new PushMessage([
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $target->_id],
            ],
        ]);

        $result = $resolver->resolveTokensWithUsers($message, 'tenant', null);

        $this->assertSame(['target-token'], $result['tokens']);
        $this->assertSame(['target-token' => (string) $target->_id], $result['token_user_map']);
        $this->assertSame(1, $gateway->chunkTargetsByUserIdsCalls);
        $this->assertSame(0, $gateway->chunkAllTargetsCalls);
        $this->assertSame([(string) $target->_id], $gateway->seenUserIds);
        $this->assertSame([500], $gateway->seenBatchSizes);
    }

    public function test_recipient_resolver_account_scope_excludes_foreign_direct_user_even_when_id_is_explicit(): void
    {
        $foreignAccount = Account::query()->create([
            'name' => 'Foreign Push Account',
            'document' => 'DOC-FOREIGN-PUSH',
        ]);
        $foreignRole = $foreignAccount->roleTemplates()->create([
            'name' => 'Foreign Push Role',
            'permissions' => ['push-messages:*'],
        ]);
        $foreignUser = $this->userService->create($foreignAccount, [
            'name' => 'Foreign Push User',
            'email' => 'foreign-push-user@example.org',
            'password' => 'Secret!234',
        ], (string) $foreignRole->_id);
        $this->seedPushDevice($foreignUser, [
            'device_id' => 'foreign-account-device',
            'platform' => 'android',
            'push_token' => 'foreign-account-token',
            'is_active' => true,
        ]);

        $gateway = new class extends PushUserGatewayAdapter
        {
            public int $chunkAllTargetsCalls = 0;

            public int $chunkTargetsByUserIdsCalls = 0;

            /** @var array<int, string> */
            public array $seenUserIds = [];

            public ?string $receivedAccountId = null;

            public function chunkActivePushTargetsByUserIds(?string $accountId, array $userIds, int $chunkSize, callable $callback): void
            {
                $this->chunkTargetsByUserIdsCalls++;
                $this->receivedAccountId = $accountId;
                $this->seenUserIds = array_values($userIds);

                parent::chunkActivePushTargetsByUserIds($accountId, $userIds, $chunkSize, $callback);
            }

            public function chunkActivePushTargets(?string $accountId, int $chunkSize, callable $callback): void
            {
                $this->chunkAllTargetsCalls++;

                parent::chunkActivePushTargets($accountId, $chunkSize, $callback);
            }
        };

        $resolver = new PushRecipientResolver(
            $gateway,
            $this->app->make(\Belluga\PushHandler\Services\PushAudienceTopologyClassifier::class),
        );

        $message = new PushMessage([
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $foreignUser->_id],
            ],
        ]);

        $result = $resolver->resolveTokensWithUsers($message, 'account', (string) $this->account->_id);

        $this->assertSame([], $result['tokens']);
        $this->assertSame([], $result['token_user_map']);
        $this->assertSame(1, $gateway->chunkTargetsByUserIdsCalls);
        $this->assertSame(0, $gateway->chunkAllTargetsCalls);
        $this->assertSame((string) $this->account->_id, $gateway->receivedAccountId);
        $this->assertSame([(string) $foreignUser->_id], $gateway->seenUserIds);
    }

    public function test_recipient_resolver_rejects_multi_user_direct_audience_at_helper_boundary(): void
    {
        $other = $this->userService->create($this->account, [
            'name' => 'Other Direct Push User',
            'email' => 'other-direct-push-user@example.org',
            'password' => 'Secret!234',
        ], (string) $this->operatorRole->_id);

        $gateway = new class extends PushUserGatewayAdapter
        {
            public int $chunkTargetsByUserIdsCalls = 0;

            public function chunkActivePushTargetsByUserIds(?string $accountId, array $userIds, int $chunkSize, callable $callback): void
            {
                $this->chunkTargetsByUserIdsCalls++;

                parent::chunkActivePushTargetsByUserIds($accountId, $userIds, $chunkSize, $callback);
            }
        };

        $resolver = new PushRecipientResolver(
            $gateway,
            $this->app->make(\Belluga\PushHandler\Services\PushAudienceTopologyClassifier::class),
        );

        $message = new PushMessage([
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $this->operator->_id, (string) $other->_id],
            ],
        ]);

        $this->expectException(ValidationException::class);

        try {
            $resolver->resolveTokensWithUsers($message, 'account', (string) $this->account->_id);
        } finally {
            $this->assertSame(0, $gateway->chunkTargetsByUserIdsCalls);
        }
    }

    public function test_recipient_resolver_single_direct_user_stays_on_query_based_id_path(): void
    {
        $target = $this->userService->create($this->account, [
            'name' => 'Large Direct Audience User',
            'email' => 'large-direct-audience-user@example.org',
            'password' => 'Secret!234',
        ], (string) $this->operatorRole->_id);

        $expectedTokens = [];
        $expectedTokenUserMap = [];

        for ($index = 0; $index < 205; $index++) {
            $token = sprintf('large-audience-token-%03d', $index);
            $this->seedPushDevice($target, [
                'device_id' => sprintf('large-audience-device-%03d', $index),
                'platform' => 'android',
                'push_token' => $token,
                'is_active' => true,
            ]);

            $expectedTokens[] = $token;
            $expectedTokenUserMap[$token] = (string) $target->_id;
        }

        $gateway = new class extends PushUserGatewayAdapter
        {
            public int $chunkAllTargetsCalls = 0;

            public int $chunkTargetsByUserIdsCalls = 0;

            /** @var array<int, int> */
            public array $receivedTargetCounts = [];

            /** @var array<int, int> */
            public array $receivedChunkSizes = [];

            public function chunkActivePushTargetsByUserIds(?string $accountId, array $userIds, int $chunkSize, callable $callback): void
            {
                $this->chunkTargetsByUserIdsCalls++;
                $this->receivedChunkSizes[] = $chunkSize;

                parent::chunkActivePushTargetsByUserIds(
                    $accountId,
                    $userIds,
                    $chunkSize,
                    function (array $targets) use ($callback): void {
                        $this->receivedTargetCounts[] = count($targets);
                        $callback($targets);
                    }
                );
            }

            public function chunkActivePushTargets(?string $accountId, int $chunkSize, callable $callback): void
            {
                $this->chunkAllTargetsCalls++;

                parent::chunkActivePushTargets($accountId, $chunkSize, $callback);
            }
        };

        $resolver = new PushRecipientResolver(
            $gateway,
            $this->app->make(\Belluga\PushHandler\Services\PushAudienceTopologyClassifier::class),
        );

        $message = new PushMessage([
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $target->_id],
            ],
        ]);

        $result = $resolver->resolveTokensWithUsers($message, 'account', (string) $this->account->_id);

        $this->assertEqualsCanonicalizing($expectedTokens, $result['tokens']);
        $this->assertSame($expectedTokenUserMap, $result['token_user_map']);
        $this->assertSame(1, $gateway->chunkTargetsByUserIdsCalls);
        $this->assertSame(0, $gateway->chunkAllTargetsCalls);
        $this->assertSame([500], $gateway->receivedChunkSizes);
        $this->assertSame([205], $gateway->receivedTargetCounts);
    }

    public function test_push_device_account_scope_sync_tracks_attach_and_detach_changes(): void
    {
        $user = $this->userService->create($this->account, [
            'name' => 'Scoped Push User',
            'email' => 'scoped-push-user@example.org',
            'password' => 'Secret!234',
        ], (string) $this->operatorRole->_id);
        $this->seedPushDevice($user, [
            'device_id' => 'scoped-sync-device',
            'platform' => 'android',
            'push_token' => 'scoped-sync-token',
            'is_active' => true,
        ]);

        $secondAccount = Account::query()->create([
            'name' => 'Second Push Account',
            'document' => 'DOC-SECOND-PUSH',
        ]);
        $secondRole = $secondAccount->roleTemplates()->create([
            'name' => 'Second Push Admin',
            'permissions' => ['push-messages:*'],
        ]);

        $resolver = $this->app->make(PushRecipientResolver::class);
        $message = new PushMessage([
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $user->_id],
            ],
        ]);

        $beforeAttach = $resolver->resolveTokensWithUsers($message, 'account', (string) $secondAccount->_id);
        $this->assertSame([], $beforeAttach['tokens']);

        $accountManagement = $this->app->make(AccountManagementService::class);
        $accountManagement->attachUser($secondAccount, $user->fresh(), $secondRole->fresh());

        $afterAttach = $resolver->resolveTokensWithUsers($message, 'account', (string) $secondAccount->_id);
        $this->assertSame(['scoped-sync-token'], $afterAttach['tokens']);
        $this->assertSame(['scoped-sync-token' => (string) $user->_id], $afterAttach['token_user_map']);

        $refreshedUser = AccountUser::query()->where('_id', $user->_id)->firstOrFail();
        $accountManagement->detachUser($secondAccount, $refreshedUser, $secondRole->fresh());

        $afterDetach = $resolver->resolveTokensWithUsers($message, 'account', (string) $secondAccount->_id);
        $this->assertSame([], $afterDetach['tokens']);
    }

    public function test_removing_last_account_access_deactivates_push_devices(): void
    {
        TenantPushSettings::query()->delete();
        TenantPushSettings::create($this->buildTenantSettingsPayload([
            'push' => [
                'enabled' => true,
            ],
        ]));

        $transport = new \Tests\Fakes\FakePushTopicTransport();
        $this->app->instance(\Belluga\PushHandler\Contracts\PushTopicTransportContract::class, $transport);

        $user = $this->userService->create($this->account, [
            'name' => 'Removable Push User',
            'email' => 'removable-push-user@example.org',
            'password' => 'Secret!234',
        ], (string) $this->operatorRole->_id);
        $this->seedPushDevice($user, [
            'device_id' => 'removable-device',
            'platform' => 'android',
            'push_token' => 'removable-token',
            'is_active' => true,
        ]);

        $this->userService->remove($this->account, $user->fresh());

        $device = PushDevice::query()
            ->where('account_user_id', (string) $user->_id)
            ->where('device_id', 'removable-device')
            ->firstOrFail();

        $this->assertFalse((bool) $device->is_active);
        $this->assertNotNull($device->invalidated_at);
        $this->assertSame([['removable-token']], $transport->unsubscribeAll);
    }

    public function test_detaching_last_account_access_deactivates_and_unsubscribes_push_devices(): void
    {
        TenantPushSettings::query()->delete();
        TenantPushSettings::create($this->buildTenantSettingsPayload([
            'push' => [
                'enabled' => true,
            ],
        ]));

        $transport = new \Tests\Fakes\FakePushTopicTransport();
        $this->app->instance(\Belluga\PushHandler\Contracts\PushTopicTransportContract::class, $transport);

        $user = $this->userService->create($this->account, [
            'name' => 'Detachable Push User',
            'email' => 'detachable-push-user@example.org',
            'password' => 'Secret!234',
        ], (string) $this->operatorRole->_id);
        $this->seedPushDevice($user, [
            'device_id' => 'detachable-device',
            'platform' => 'android',
            'push_token' => 'detachable-token',
            'is_active' => true,
        ]);

        $this->app->make(AccountManagementService::class)->detachUser(
            $this->account,
            $user->fresh(),
            $this->operatorRole->fresh()
        );

        $device = PushDevice::query()
            ->where('account_user_id', (string) $user->_id)
            ->where('device_id', 'detachable-device')
            ->firstOrFail();

        $this->assertFalse((bool) $device->is_active);
        $this->assertNotNull($device->invalidated_at);
        $this->assertSame([['detachable-token']], $transport->unsubscribeAll);
    }

    public function test_stale_favorite_subscribe_job_reconciles_current_truth_and_unsubscribes(): void
    {
        TenantPushSettings::query()->delete();
        TenantPushSettings::create($this->buildTenantSettingsPayload([
            'push' => [
                'enabled' => true,
            ],
        ]));

        $transport = new \Tests\Fakes\FakePushTopicTransport();
        $this->app->instance(\Belluga\PushHandler\Contracts\PushTopicTransportContract::class, $transport);

        $user = AccountUser::query()->where('_id', $this->operator->_id)->firstOrFail();
        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'artist',
            'display_name' => 'Replay Favorite Profile',
            'is_active' => true,
        ]);

        $this->seedPushDevice($user, [
            'device_id' => 'favorite-replay-device',
            'platform' => 'android',
            'push_token' => 'favorite-replay-token',
            'is_active' => true,
        ]);

        FavoriteEdge::query()->create([
            'owner_user_id' => (string) $user->_id,
            'registry_key' => 'account_profile',
            'target_type' => 'account_profile',
            'target_id' => (string) $profile->_id,
            'favorited_at' => Carbon::now(),
        ]);
        FavoriteEdge::query()
            ->where('owner_user_id', (string) $user->_id)
            ->where('target_id', (string) $profile->_id)
            ->delete();

        SyncFavoriteAccountProfileTopicMembershipJob::dispatchSync(
            tenantSlug: $this->resolvePrimaryPushTenant()->slug,
            userId: (string) $user->_id,
            accountProfileId: (string) $profile->_id,
        );

        $topic = $this->app->make(PushChannelNamingService::class)
            ->favoriteAccountProfileTopic((string) $profile->_id);

        $this->assertSame([], $transport->subscriptions);
        $this->assertSame([
            [
                'topic' => $topic,
                'tokens' => ['favorite-replay-token'],
            ],
        ], $transport->unsubscriptions);
    }

    public function test_stale_event_subscribe_job_reconciles_current_truth_and_unsubscribes(): void
    {
        TenantPushSettings::query()->delete();
        TenantPushSettings::create($this->buildTenantSettingsPayload([
            'push' => [
                'enabled' => true,
            ],
        ]));

        $transport = new \Tests\Fakes\FakePushTopicTransport();
        $this->app->instance(\Belluga\PushHandler\Contracts\PushTopicTransportContract::class, $transport);

        $user = AccountUser::query()->where('_id', $this->operator->_id)->firstOrFail();
        $this->seedPushDevice($user, [
            'device_id' => 'event-replay-device',
            'platform' => 'android',
            'push_token' => 'event-replay-token',
            'is_active' => true,
        ]);

        $event = Event::query()->create([
            'title' => 'Replay Confirmed Event',
            'account_context_ids' => [(string) $this->account->_id],
        ]);

        /** @var AttendanceCommitmentService $attendance */
        $attendance = $this->app->make(AttendanceCommitmentService::class);
        $attendance->confirm((string) $user->_id, (string) $event->_id, 'occ-replay');
        $attendance->unconfirm((string) $user->_id, (string) $event->_id, 'occ-replay');

        $transport->subscriptions = [];
        $transport->unsubscriptions = [];
        $transport->unsubscribeAll = [];

        SyncEventConfirmedTopicMembershipJob::dispatchSync(
            tenantSlug: $this->resolvePrimaryPushTenant()->slug,
            userId: (string) $user->_id,
            eventId: (string) $event->_id,
        );

        $topic = $this->app->make(PushChannelNamingService::class)
            ->confirmedEventTopic((string) $event->_id);

        $this->assertSame([], $transport->subscriptions);
        $this->assertSame([
            [
                'topic' => $topic,
                'tokens' => ['event-replay-token'],
            ],
        ], $transport->unsubscriptions);
    }

    public function test_sync_user_favorite_profile_membership_uses_exact_truth_lookup(): void
    {
        $user = AccountUser::query()->where('_id', $this->operator->_id)->firstOrFail();
        $this->seedPushDevice($user, [
            'device_id' => 'favorite-exact-lookup-device',
            'platform' => 'android',
            'push_token' => 'favorite-exact-lookup-token',
            'is_active' => true,
        ]);

        $transport = $this->createMock(\Belluga\PushHandler\Contracts\PushTopicTransportContract::class);
        $transport->expects($this->once())
            ->method('subscribe')
            ->with('favorite-topic', ['favorite-exact-lookup-token']);

        $projection = $this->getMockBuilder(PushUserTopicProjectionService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['userHasFavoriteAccountProfile', 'favoriteProfileTopicsForUserId'])
            ->getMock();
        $projection->expects($this->once())
            ->method('userHasFavoriteAccountProfile')
            ->with((string) $user->_id, 'favorite-profile-id')
            ->willReturn(true);
        $projection->expects($this->never())
            ->method('favoriteProfileTopicsForUserId');

        $naming = $this->createMock(PushChannelNamingService::class);
        $naming->expects($this->once())
            ->method('favoriteAccountProfileTopic')
            ->with('favorite-profile-id')
            ->willReturn('favorite-topic');

        $settings = $this->createMock(PushSettingsKernelBridge::class);
        $settings->method('resolvedPushConfig')->willReturn(['enabled' => true]);
        $settings->method('currentFirebaseConfig')->willReturn(['project_id' => 'tenant-zeta']);
        $settings->method('hasRequiredFirebaseConfig')->willReturn(true);

        $credentials = $this->createMock(PushCredentialService::class);
        $credentials->method('current')->willReturn(new PushCredential());

        $service = new PushTopicMembershipService(
            transport: $transport,
            projection: $projection,
            naming: $naming,
            pushSettings: $settings,
            credentials: $credentials,
        );

        $service->syncUserFavoriteProfileMembership((string) $user->_id, 'favorite-profile-id');
    }

    public function test_sync_user_confirmed_event_membership_uses_exact_truth_lookup(): void
    {
        $user = AccountUser::query()->where('_id', $this->operator->_id)->firstOrFail();
        $this->seedPushDevice($user, [
            'device_id' => 'event-exact-lookup-device',
            'platform' => 'android',
            'push_token' => 'event-exact-lookup-token',
            'is_active' => true,
        ]);

        $transport = $this->createMock(\Belluga\PushHandler\Contracts\PushTopicTransportContract::class);
        $transport->expects($this->once())
            ->method('subscribe')
            ->with('event-topic', ['event-exact-lookup-token']);

        $projection = $this->getMockBuilder(PushUserTopicProjectionService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['userHasConfirmedEvent', 'confirmedEventTopicsForUserId'])
            ->getMock();
        $projection->expects($this->once())
            ->method('userHasConfirmedEvent')
            ->with((string) $user->_id, 'event-id')
            ->willReturn(true);
        $projection->expects($this->never())
            ->method('confirmedEventTopicsForUserId');

        $naming = $this->createMock(PushChannelNamingService::class);
        $naming->expects($this->once())
            ->method('confirmedEventTopic')
            ->with('event-id')
            ->willReturn('event-topic');

        $settings = $this->createMock(PushSettingsKernelBridge::class);
        $settings->method('resolvedPushConfig')->willReturn(['enabled' => true]);
        $settings->method('currentFirebaseConfig')->willReturn(['project_id' => 'tenant-zeta']);
        $settings->method('hasRequiredFirebaseConfig')->willReturn(true);

        $credentials = $this->createMock(PushCredentialService::class);
        $credentials->method('current')->willReturn(new PushCredential());

        $service = new PushTopicMembershipService(
            transport: $transport,
            projection: $projection,
            naming: $naming,
            pushSettings: $settings,
            credentials: $credentials,
        );

        $service->syncUserConfirmedEventMembership((string) $user->_id, 'event-id');
    }

    public function test_event_confirmed_eligibility_uses_exact_truth_lookup(): void
    {
        $attendance = $this->getMockBuilder(AttendanceCommitmentService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['hasConfirmedEvent', 'confirmedEventIds'])
            ->getMock();
        $attendance->expects($this->once())
            ->method('hasConfirmedEvent')
            ->with((string) $this->operator->_id, 'event-id')
            ->willReturn(true);
        $attendance->expects($this->never())
            ->method('confirmedEventIds');

        $service = new PushAudienceEligibilityService($attendance);

        $eligible = $service->isEligible(
            user: $this->operator,
            message: new PushMessage(),
            audience: [
                'type' => 'event_confirmed',
                'event_id' => 'event-id',
            ],
        );

        $this->assertTrue($eligible);
    }

    public function test_push_device_registered_event_reconciles_topics_for_post_write_flow(): void
    {
        TenantPushSettings::query()->delete();
        TenantPushSettings::create($this->buildTenantSettingsPayload([
            'push' => [
                'enabled' => true,
            ],
        ]));

        $transport = new \Tests\Fakes\FakePushTopicTransport();
        $this->app->instance(\Belluga\PushHandler\Contracts\PushTopicTransportContract::class, $transport);

        $tenant = $this->resolvePrimaryPushTenant();
        $tenant->makeCurrent();
        $this->seedPushDevice($this->operator, [
            'device_id' => 'post-write-device',
            'platform' => 'android',
            'push_token' => 'post-write-token',
            'is_active' => true,
        ]);

        event(new PushDeviceRegistered((string) $this->operator->_id, 'post-write-token'));

        $this->assertSame([['post-write-token']], $transport->unsubscribeAll);
        $this->assertSame([
            [
                'topic' => $this->app->make(PushChannelNamingService::class)->allUsersTopic(),
                'tokens' => ['post-write-token'],
            ],
        ], $transport->subscriptions);
    }

    public function test_push_device_registered_event_noops_without_tenant_context(): void
    {
        TenantPushSettings::query()->delete();
        TenantPushSettings::create($this->buildTenantSettingsPayload([
            'push' => [
                'enabled' => true,
            ],
        ]));

        $transport = new \Tests\Fakes\FakePushTopicTransport();
        $this->app->instance(\Belluga\PushHandler\Contracts\PushTopicTransportContract::class, $transport);

        $this->resolvePrimaryPushTenant()->makeCurrent();
        $this->seedPushDevice($this->operator, [
            'device_id' => 'no-tenant-device',
            'platform' => 'android',
            'push_token' => 'no-tenant-token',
            'is_active' => true,
        ]);

        Tenant::forgetCurrent();
        event(new PushDeviceRegistered((string) $this->operator->_id, 'no-tenant-token'));

        $this->assertSame([], $transport->unsubscribeAll);
        $this->assertSame([], $transport->subscriptions);

        $this->resolvePrimaryPushTenant()->makeCurrent();
    }

    public function test_favorite_added_event_subscribes_profile_topic_for_post_write_flow(): void
    {
        TenantPushSettings::query()->delete();
        TenantPushSettings::create($this->buildTenantSettingsPayload([
            'push' => [
                'enabled' => true,
            ],
        ]));

        $tenant = $this->resolvePrimaryPushTenant();
        $tenant->makeCurrent();
        $transport = new \Tests\Fakes\FakePushTopicTransport();
        $this->app->instance(\Belluga\PushHandler\Contracts\PushTopicTransportContract::class, $transport);
        $user = AccountUser::query()->where('_id', $this->operator->_id)->firstOrFail();
        $this->seedPushDevice($user, [
            'device_id' => 'favorite-after-write-device',
            'platform' => 'android',
            'push_token' => 'favorite-after-write-token',
            'is_active' => true,
        ]);

        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'artist',
            'display_name' => 'Favorite After Write Profile',
            'is_active' => true,
        ]);

        FavoriteEdge::query()->create([
            'owner_user_id' => (string) $user->_id,
            'registry_key' => 'account_profile',
            'target_type' => 'account_profile',
            'target_id' => (string) $profile->_id,
            'favorited_at' => Carbon::now(),
        ]);

        event(new FavoriteAdded(
            ownerUserId: (string) $user->_id,
            registryKey: 'account_profile',
            targetType: 'account_profile',
            targetId: (string) $profile->_id,
        ));

        $this->assertSame([
            [
                'topic' => $this->app->make(PushChannelNamingService::class)
                    ->favoriteAccountProfileTopic((string) $profile->_id),
                'tokens' => ['favorite-after-write-token'],
            ],
        ], $transport->subscriptions);
        $this->assertSame([], $transport->unsubscriptions);
    }

    public function test_occurrence_attendance_confirmed_event_subscribes_confirmed_event_topic_for_post_write_flow(): void
    {
        TenantPushSettings::query()->delete();
        TenantPushSettings::create($this->buildTenantSettingsPayload([
            'push' => [
                'enabled' => true,
            ],
        ]));

        $tenant = $this->resolvePrimaryPushTenant();
        $tenant->makeCurrent();
        $transport = new \Tests\Fakes\FakePushTopicTransport();
        $this->app->instance(\Belluga\PushHandler\Contracts\PushTopicTransportContract::class, $transport);
        $user = AccountUser::query()->where('_id', $this->operator->_id)->firstOrFail();
        $this->seedPushDevice($user, [
            'device_id' => 'attendance-after-write-device',
            'platform' => 'android',
            'push_token' => 'attendance-after-write-token',
            'is_active' => true,
        ]);

        $event = Event::query()->create([
            'title' => 'Attendance After Write Event',
            'account_context_ids' => [(string) $this->account->_id],
        ]);

        $this->app->make(AttendanceCommitmentService::class)->confirm(
            (string) $user->_id,
            (string) $event->_id,
            'attendance-after-write-occurrence'
        );

        $this->assertSame([
            [
                'topic' => $this->app->make(PushChannelNamingService::class)
                    ->confirmedEventTopic((string) $event->_id),
                'tokens' => ['attendance-after-write-token'],
            ],
        ], $transport->subscriptions);
        $this->assertSame([], $transport->unsubscriptions);
    }

    public function test_reconcile_push_token_topics_job_uses_explicit_tenant_slug_instead_of_ambient_context(): void
    {
        TenantPushSettings::query()->delete();
        TenantPushSettings::create($this->buildTenantSettingsPayload([
            'push' => [
                'enabled' => true,
            ],
        ]));

        $transport = new \Tests\Fakes\FakePushTopicTransport();
        $this->app->instance(\Belluga\PushHandler\Contracts\PushTopicTransportContract::class, $transport);

        $primaryTenant = $this->resolvePrimaryPushTenant();
        $primaryTenant->makeCurrent();

        $user = $this->userService->create($this->account, [
            'name' => 'Tenant Isolation Push User',
            'email' => 'tenant-isolation-push-user@example.org',
            'password' => 'Secret!234',
        ], (string) $this->operatorRole->_id);
        $this->seedPushDevice($user, [
            'device_id' => 'tenant-isolation-device',
            'platform' => 'android',
            'push_token' => 'tenant-isolation-token',
            'is_active' => true,
        ]);

        $naming = $this->app->make(PushChannelNamingService::class);
        $primaryTopic = $naming->allUsersTopic();
        [$secondaryTenant] = $this->seedSecondaryTenantContext();

        $secondaryTopic = $this->app->make(PushChannelNamingService::class)->allUsersTopic();

        ReconcilePushTokenTopicsJob::dispatchSync(
            tenantSlug: (string) $primaryTenant->slug,
            userId: (string) $user->_id,
            pushToken: 'tenant-isolation-token',
        );

        $this->assertNotSame($primaryTopic, $secondaryTopic);
        $this->assertSame((string) $secondaryTenant->slug, (string) Tenant::current()?->slug);
        $this->assertSame([['tenant-isolation-token']], $transport->unsubscribeAll);
        $this->assertSame([
            [
                'topic' => $primaryTopic,
                'tokens' => ['tenant-isolation-token'],
            ],
        ], $transport->subscriptions);
    }

    public function test_recipient_resolver_fails_closed_for_unmaterialized_event_audience(): void
    {
        $user = AccountUser::query()->where('_id', $this->operator->_id)->firstOrFail();
        $this->seedPushDevice($user, [
            'device_id' => 'event-device',
            'platform' => 'android',
            'push_token' => 'event-token',
            'is_active' => true,
        ]);

        $gateway = new class extends PushUserGatewayAdapter
        {
            public int $chunkAllTargetsCalls = 0;

            public int $chunkTargetsByUserIdsCalls = 0;

            public function chunkActivePushTargetsByUserIds(?string $accountId, array $userIds, int $chunkSize, callable $callback): void
            {
                $this->chunkTargetsByUserIdsCalls++;

                parent::chunkActivePushTargetsByUserIds($accountId, $userIds, $chunkSize, $callback);
            }

            public function chunkActivePushTargets(?string $accountId, int $chunkSize, callable $callback): void
            {
                $this->chunkAllTargetsCalls++;

                parent::chunkActivePushTargets($accountId, $chunkSize, $callback);
            }
        };

        $resolver = new PushRecipientResolver(
            $gateway,
            $this->app->make(\Belluga\PushHandler\Services\PushAudienceTopologyClassifier::class),
        );

        $message = new PushMessage([
            'audience' => [
                'type' => 'event',
                'event_id' => 'event-123',
            ],
        ]);

        try {
            $resolver->resolveTokensWithUsers($message, 'tenant', null);
            $this->fail('Expected semantic audience materialization to fail closed.');
        } catch (ValidationException) {
            $this->assertSame(0, $gateway->chunkTargetsByUserIdsCalls);
            $this->assertSame(0, $gateway->chunkAllTargetsCalls);
        }
    }

    public function test_direct_recipient_source_keeps_push_device_keyset_materialization_guardrail(): void
    {
        $resolverSource = $this->readSource('packages/belluga/belluga_push_handler/src/Services/PushRecipientResolver.php');
        $gatewaySource = $this->readSource('app/Integration/Push/PushUserGatewayAdapter.php');

        $this->assertStringContainsString('directRecipientUserId($message)', $resolverSource);
        $this->assertStringContainsString('Explicit recipient materialization is only allowed for individual direct delivery.', $resolverSource);
        $this->assertStringContainsString('chunkActivePushTargetsByUserIds(', $resolverSource);
        $this->assertStringContainsString('streamResolvedTargetBatches', $resolverSource);
        $this->assertStringNotContainsString("if (\$audienceType === 'users')", $resolverSource);
        $this->assertStringNotContainsString('chunkActivePushTargets(', $resolverSource);

        $this->assertStringContainsString("options(['batchSize' => \$chunkSize])", $gatewaySource);
        $this->assertStringContainsString("orderBy('_id')", $gatewaySource);
        $this->assertStringContainsString("where('_id', '>', \$lastSeenId)", $gatewaySource);
        $this->assertStringContainsString("limit(\$chunkSize)", $gatewaySource);
        $this->assertStringContainsString("get(['_id', 'account_user_id', 'push_token'])", $gatewaySource);
        $this->assertStringNotContainsString("\$upperBoundId", $gatewaySource);
        $this->assertStringNotContainsString("get(['_id', 'devices'])", $gatewaySource);
    }

    public function test_invite_received_telemetry_uses_user_id_distinct_id(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $this->seedTelemetrySettings([
            [
                'type' => 'mixpanel',
                'token' => 'mixpanel-token',
                'events' => ['invite_received'],
            ],
            [
                'type' => 'webhook',
                'url' => 'https://telemetry.example/ingest',
                'events' => ['invite_received'],
            ],
        ]);

        $message = PushMessage::create($this->buildPayload());

        $this->app->bind(FcmClientContract::class, static function () {
            return new class implements FcmClientContract
            {
                public function send(
                    PushMessage $message,
                    array $tokens,
                    string $messageInstanceId,
                    Carbon $expiresAt,
                    int $ttlMinutes
                ): array {
                    $token = $tokens[0] ?? 'token-1';

                    return [
                        'accepted_count' => 1,
                        'responses' => [
                            [
                                'token' => $token,
                                'status' => 'accepted',
                                'provider_message_id' => 'msg-1',
                            ],
                        ],
                    ];
                }
            };
        });

        Http::fake([
            'https://api.mixpanel.com/track' => Http::response([], 200),
            'https://telemetry.example/ingest' => Http::response([], 200),
        ]);

        $service = $this->app->make(PushDeliveryService::class);
        $userId = (string) $this->operator->_id;
        $service->deliver($message, ['token-1'], ['token-1' => $userId]);

        Http::assertSent(function ($request) use ($userId) {
            if ($request->url() !== 'https://api.mixpanel.com/track') {
                return false;
            }
            $payload = $request->data();
            $properties = $payload['properties'] ?? [];

            return ($properties['distinct_id'] ?? null) === $userId
                && ($properties['user_id'] ?? null) === $userId
                && isset($properties['$insert_id']);
        });

        Http::assertSent(function ($request) use ($userId) {
            if ($request->url() !== 'https://telemetry.example/ingest') {
                return false;
            }
            $payload = $request->data();

            return ($payload['context']['user']['id'] ?? null) === $userId
                && ($payload['payload']['event'] ?? null) === 'invite_received';
        });
    }

    public function test_invite_received_telemetry_tracks_all_without_events_list(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $this->seedTelemetrySettings([
            [
                'type' => 'mixpanel',
                'token' => 'mixpanel-token',
                'track_all' => true,
            ],
            [
                'type' => 'webhook',
                'url' => 'https://telemetry.example/ingest',
                'track_all' => true,
            ],
        ]);

        $message = PushMessage::create($this->buildPayload());

        $this->app->bind(FcmClientContract::class, static function () {
            return new class implements FcmClientContract
            {
                public function send(
                    PushMessage $message,
                    array $tokens,
                    string $messageInstanceId,
                    Carbon $expiresAt,
                    int $ttlMinutes
                ): array {
                    $token = $tokens[0] ?? 'token-1';

                    return [
                        'accepted_count' => 1,
                        'responses' => [
                            [
                                'token' => $token,
                                'status' => 'accepted',
                                'provider_message_id' => 'msg-1',
                            ],
                        ],
                    ];
                }
            };
        });

        Http::fake([
            'https://api.mixpanel.com/track' => Http::response([], 200),
            'https://telemetry.example/ingest' => Http::response([], 200),
        ]);

        $service = $this->app->make(PushDeliveryService::class);
        $userId = (string) $this->operator->_id;
        $service->deliver($message, ['token-1'], ['token-1' => $userId]);

        Http::assertSent(function ($request) use ($userId) {
            if ($request->url() !== 'https://api.mixpanel.com/track') {
                return false;
            }
            $payload = $request->data();

            return ($payload['event'] ?? null) === 'invite_received'
                && ($payload['properties']['distinct_id'] ?? null) === $userId;
        });

        Http::assertSent(function ($request) use ($userId) {
            if ($request->url() !== 'https://telemetry.example/ingest') {
                return false;
            }
            $payload = $request->data();

            return ($payload['context']['user']['id'] ?? null) === $userId
                && ($payload['payload']['event'] ?? null) === 'invite_received';
        });
    }

    public function test_send_invalidates_not_found_tokens_and_skips_on_next_send(): void
    {
        $this->actingAsOperator();

        $this->app->bind(FcmClientContract::class, static function () {
            return new class implements FcmClientContract
            {
                public function send(
                    PushMessage $message,
                    array $tokens,
                    string $messageInstanceId,
                    Carbon $expiresAt,
                    int $ttlMinutes
                ): array {
                    return [
                        'accepted_count' => 0,
                        'responses' => [
                            [
                                'token' => $tokens[0] ?? '',
                                'status' => 'failed',
                                'error_code' => 'NOT_FOUND',
                                'error_message' => 'Requested entity was not found.',
                            ],
                        ],
                    ];
                }
            };
        });

        $payload = $this->buildPayload([
            'type' => 'transactional',
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $this->operator->_id],
            ],
        ]);

        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $this->seedPushDevice($this->operator, [
            'device_id' => 'device-1',
            'push_token' => 'token-1',
            'platform' => 'android',
        ]);

        $send = $this->postJson($this->baseUrl.'/'.$messageId.'/send');
        $send->assertStatus(422);
        $send->assertJsonPath('reason', 'delivery_failed');

        $device = PushDevice::query()
            ->where('account_user_id', (string) $this->operator->_id)
            ->where('device_id', 'device-1')
            ->firstOrFail();
        $this->assertFalse((bool) $device->is_active);

        $message = PushMessage::query()->findOrFail($messageId);
        $this->assertSame(0, $message->metrics['accepted_count'] ?? null);
        $this->assertSame(0, $message->metrics['sent_count'] ?? null);
        $this->assertNotSame('sent', $message->status);
        $this->assertNull($message->sent_at);

        $retry = $this->postJson($this->baseUrl.'/'.$messageId.'/send', [
            'dry_run' => true,
        ]);
        $retry->assertStatus(422);
        $retry->assertJsonPath('reason', 'no_tokens');
    }

    public function test_tenant_transactional_send_returns_delivery_failed_when_provider_accepts_none(): void
    {
        Sanctum::actingAs($this->operator, [
            'tenant-push-messages:create',
            'tenant-push-messages:send',
        ]);

        $this->app->bind(FcmClientContract::class, static function () {
            return new class implements FcmClientContract
            {
                public function send(
                    PushMessage $message,
                    array $tokens,
                    string $messageInstanceId,
                    Carbon $expiresAt,
                    int $ttlMinutes
                ): array {
                    return [
                        'accepted_count' => 0,
                        'responses' => array_map(static fn (string $token): array => [
                            'token' => $token,
                            'status' => 'failed',
                            'error_code' => 'UNAVAILABLE',
                            'error_message' => 'Provider unavailable.',
                        ], $tokens),
                    ];
                }
            };
        });

        $payload = $this->buildPayload([
            'scope' => 'tenant',
            'type' => 'transactional',
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $this->operator->_id],
            ],
        ]);

        $create = $this->postJson('api/v1/push/messages', $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $this->seedPushDevice($this->operator, [
            'device_id' => 'tenant-device-1',
            'push_token' => 'tenant-token-1',
        ]);

        $send = $this->postJson('api/v1/push/messages/'.$messageId.'/send');

        $send->assertStatus(422);
        $send->assertJsonPath('reason', 'delivery_failed');

        $message = PushMessage::query()->findOrFail($messageId);
        $this->assertSame(0, $message->metrics['accepted_count'] ?? null);
        $this->assertSame(0, $message->metrics['sent_count'] ?? null);
        $this->assertNotSame('sent', $message->status);
        $this->assertNull($message->sent_at);
    }

    public function test_transactional_send_denied_when_eligibility_fails(): void
    {
        $this->actingAsOperator();

        $this->app->bind(PushAudienceEligibilityContract::class, static function () {
            return new class implements PushAudienceEligibilityContract
            {
                public function isEligible(
                    Authenticatable $user,
                    PushMessage $message,
                    array $audience,
                    array $context = []
                ): bool {
                    return false;
                }
            };
        });

        $payload = $this->buildPayload([
            'type' => 'transactional',
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $this->operator->_id],
            ],
        ]);

        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $send = $this->postJson($this->baseUrl.'/'.$messageId.'/send', [
            'dry_run' => true,
        ]);

        $send->assertStatus(403);
        $send->assertJsonPath('reason', 'forbidden');
    }

    public function test_send_returns_inactive_when_scope_mismatch(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'type' => 'transactional',
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $this->operator->_id],
            ],
        ]);

        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $this->withServerVariables([
            'HTTP_HOST' => $this->tenantHost,
        ]);
        Sanctum::actingAs($this->operator, ['tenant-push-messages:send']);

        $send = $this->postJson('api/v1/push/messages/'.$messageId.'/send', [
            'dry_run' => true,
        ]);

        $send->assertStatus(422);
        $send->assertJsonPath('reason', 'inactive');
    }

    private function actingAsOperator(): void
    {
        $this->withServerVariables([
            'HTTP_HOST' => $this->tenantHost,
        ]);
        Sanctum::actingAs($this->operator, [
            'push-messages:read',
            'push-messages:create',
            'push-messages:update',
            'push-messages:delete',
            'push-messages:send',
            'push-settings:update',
        ]);
    }

    /**
     * @param  array<int, string>  $abilities
     */
    private function createAccountPushMessageWithBearerToken(array $abilities = ['push-messages:create']): string
    {
        $token = $this->app->make(TenantScopedAccessTokenService::class)->issueForAccountUser(
            $this->operator,
            'account-push-create',
            $abilities,
            tenantId: (string) Tenant::current()?->_id,
            accountId: (string) $this->account->_id
        );

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $this->operator->_id],
            ],
        ]);

        $create = $this
            ->withHeaders(['Authorization' => "Bearer {$token->plainTextToken}"])
            ->postJson($this->baseUrl, $payload);
        $create->assertCreated();
        $this->app['auth']->forgetGuards();

        return $this->resolveMessageId($payload['internal_name']);
    }

    private function assertAccountPushMessageDataAndActionsAcceptBearerToken(string $messageId, string $plainTextToken): void
    {
        $this->app['auth']->forgetGuards();

        $data = $this
            ->withHeaders(['Authorization' => "Bearer {$plainTextToken}"])
            ->getJson($this->baseUrl.'/'.$messageId.'/data');
        $data->assertOk();
        $data->assertJsonPath('ok', true);

        $this->app['auth']->forgetGuards();

        $action = $this
            ->withHeaders(['Authorization' => "Bearer {$plainTextToken}"])
            ->postJson($this->baseUrl.'/'.$messageId.'/actions', [
                'action' => 'opened',
                'step_index' => 0,
                'idempotency_key' => 'opened:'.$messageId.':'.Str::uuid()->toString(),
            ]);
        $action->assertOk();
    }

    private function assertAccountPushMessageDataAndActionsRejectBearerToken(string $messageId, string $plainTextToken): void
    {
        $this->app['auth']->forgetGuards();

        $data = $this
            ->withHeaders(['Authorization' => "Bearer {$plainTextToken}"])
            ->getJson($this->baseUrl.'/'.$messageId.'/data');
        $data->assertStatus(403);

        $this->app['auth']->forgetGuards();

        $action = $this
            ->withHeaders(['Authorization' => "Bearer {$plainTextToken}"])
            ->postJson($this->baseUrl.'/'.$messageId.'/actions', [
                'action' => 'opened',
                'step_index' => 0,
                'idempotency_key' => 'opened:'.$messageId.':'.Str::uuid()->toString(),
            ]);
        $action->assertStatus(403);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function buildPayload(array $overrides = []): array
    {
        $payload = [
            'internal_name' => 'message-'.Str::uuid()->toString(),
            'title_template' => 'Hello {{user_name}}',
            'body_template' => 'Body text',
            'type' => 'invite_received',
            'active' => true,
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $this->operator->_id],
            ],
            'delivery' => [],
            'payload_template' => [
                'layoutType' => 'fullScreen',
                'closeBehavior' => 'after_action',
                'steps' => [
                    [
                        'slug' => 'intro',
                        'type' => 'copy',
                        'title' => 'Title',
                        'body' => 'Body text',
                    ],
                ],
                'buttons' => [
                    [
                        'label' => 'Agenda',
                        'action' => [
                            'type' => 'route',
                            'route_key' => 'agenda.search',
                            'path_parameters' => [],
                            'query_parameters' => [
                                'startSearchActive' => true,
                            ],
                        ],
                        'color' => '#FFFFFF',
                    ],
                ],
            ],
            'template_defaults' => [
                ['key' => 'user_name', 'value' => 'user.name', 'default' => 'Friend'],
            ],
        ];

        return array_replace_recursive($payload, $overrides);
    }

    private function resolveMessageId(string $internalName): string
    {
        $message = PushMessage::query()->where('internal_name', $internalName)->firstOrFail();

        return (string) $message->_id;
    }

    private function seedPushSettings(): void
    {
        TenantPushSettings::query()->delete();
        PushCredential::query()->delete();
        PushCredential::create([
            'project_id' => 'project-id',
            'client_email' => 'client@example.org',
            'private_key' => 'secret',
        ]);
        TenantPushSettings::create($this->buildTenantSettingsPayload([
            'push' => [
                'message_routes' => [
                    [
                        'key' => 'agenda.search',
                        'path' => '/agenda',
                        'path_params' => [],
                        'query_params' => [
                            'startSearchActive' => 'boolean',
                            'initialSearchQuery' => 'string',
                        ],
                    ],
                    [
                        'key' => 'agenda.detail',
                        'path' => '/agenda/evento/:slug',
                        'path_params' => ['slug'],
                        'query_params' => [
                            'startWithHistory' => 'boolean',
                        ],
                    ],
                ],
            ],
        ]));
    }

    /**
     * @param  array<int, array<string, mixed>>  $trackers
     */
    private function seedTelemetrySettings(array $trackers, ?int $locationFreshnessMinutes = null): void
    {
        $payload = [
            'location_freshness_minutes' => $locationFreshnessMinutes ?? (int) config('telemetry.location_freshness_minutes', 5),
            'trackers' => $trackers,
        ];

        $settings = TenantSettings::current();
        if (! $settings) {
            TenantSettings::create(['telemetry' => $payload]);

            return;
        }

        $settings->fill(['telemetry' => $payload]);
        $settings->save();
    }

    private function buildTenantSettingsPayload(array $overrides = []): array
    {
        $credential = PushCredential::query()->first();
        if (! $credential) {
            $credential = PushCredential::create([
                'project_id' => 'project-id',
                'client_email' => 'client@example.org',
                'private_key' => 'secret',
            ]);
        }

        $payload = [
            'firebase' => [
                'apiKey' => 'key',
                'appId' => 'app',
                'projectId' => 'project',
                'messagingSenderId' => 'sender',
                'storageBucket' => 'bucket',
            ],
            'push' => [
                'max_ttl_days' => 30,
                'message_types' => [
                    [
                        'key' => 'invite_received',
                        'label' => 'Invite Received',
                    ],
                ],
            ],
        ];

        return array_replace_recursive($payload, $overrides);
    }

    protected function resolveTenantForAccountSeed(): Tenant
    {
        return $this->resolvePrimaryPushTenant();
    }

    private function resolvePrimaryPushTenant(): Tenant
    {
        $tenant = Tenant::query()
            ->where('subdomain', 'tenant-zeta')
            ->first();

        if (! $tenant instanceof Tenant) {
            throw new \RuntimeException('Unable to resolve push flow primary tenant (expected subdomain: tenant-zeta).');
        }

        $this->landlord->tenant_primary->subdomain = $tenant->subdomain;
        $this->landlord->tenant_primary->slug = $tenant->slug;
        $this->landlord->tenant_primary->id = (string) $tenant->_id;

        return $tenant;
    }

    private function readSource(string $relativePath): string
    {
        $path = base_path($relativePath);
        $contents = file_get_contents($path);

        if (! is_string($contents) || $contents === '') {
            throw new \RuntimeException(sprintf('Unable to read source file [%s].', $relativePath));
        }

        return $contents;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function seedPushDevice(AccountUser $user, array $attributes): PushDevice
    {
        return PushDevice::query()->create([
            'tenant_id' => (string) (Tenant::current()?->_id ?? Tenant::current()?->id ?? ''),
            'account_user_id' => (string) $user->_id,
            'account_ids' => $attributes['account_ids'] ?? $user->getAccessToIds(),
            'device_id' => $attributes['device_id'] ?? 'device-'.Str::random(6),
            'platform' => $attributes['platform'] ?? 'android',
            'push_token' => $attributes['push_token'] ?? 'token-'.Str::random(12),
            'is_active' => $attributes['is_active'] ?? true,
            'invalidated_at' => $attributes['invalidated_at'] ?? null,
            'last_registered_at' => $attributes['last_registered_at'] ?? Carbon::now(),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $devices
     */
    private function seedPushDevices(AccountUser $user, array $devices): void
    {
        foreach ($devices as $attributes) {
            $this->seedPushDevice($user, $attributes);
        }
    }

    private function countTenantQueries(callable $callback): int
    {
        $connection = DB::connection('tenant');
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $callback();

            return count($connection->getQueryLog());
        } finally {
            $connection->disableQueryLog();
        }
    }

    /**
     * @param  array<int, string>  $topics
     */
    private function bindTopicOnlyTransportSpy(array &$topics, int $acceptedCount = 1, string $status = 'accepted'): void
    {
        $this->app->bind(FcmClientContract::class, static function () {
            return new class implements FcmClientContract
            {
                public function send(
                    PushMessage $message,
                    array $tokens,
                    string $messageInstanceId,
                    Carbon $expiresAt,
                    int $ttlMinutes
                ): array {
                    throw new \RuntimeException('Direct FCM transport must not execute for shared topic delivery tests.');
                }
            };
        });

        $this->app->bind(FcmTopicSenderContract::class, function () use (&$topics, $acceptedCount, $status) {
            return new class($topics, $acceptedCount, $status) implements FcmTopicSenderContract
            {
                /**
                 * @var array<int, string>
                 */
                private array $topics;

                /**
                 * @param  array<int, string>  $topics
                 */
                public function __construct(
                    array &$topics,
                    private readonly int $acceptedCount,
                    private readonly string $status,
                )
                {
                    $this->topics = &$topics;
                }

                public function sendTopic(
                    PushMessage $message,
                    string $topic,
                    string $messageInstanceId,
                    Carbon $expiresAt,
                    int $ttlMinutes
                ): array {
                    $this->topics[] = $topic;

                    return [
                        'accepted_count' => $this->acceptedCount,
                        'responses' => [[
                            'topic' => $topic,
                            'status' => $this->status,
                            'provider_message_id' => 'topic-'.$topic,
                        ]],
                    ];
                }
            };
        });
    }

    private function readPrivateProperty(object $object, string $property): mixed
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($object);
    }

    /**
     * @return array{0: Tenant, 1: AccountUser, 2: string, 3: Account}
     */
    private function seedSecondaryTenantContext(): array
    {
        $suffix = Str::lower(Str::random(6));
        $tenant = Tenant::create([
            'name' => 'Tenant Secondary',
            'subdomain' => 'tenant-secondary-'.$suffix,
            'app_domains' => ['tenant-secondary-'.$suffix.'.app'],
            'domains' => [],
        ]);

        $tenant->makeCurrent();
        $this->seedPushSettings();

        $account = Account::create([
            'name' => 'Account Secondary '.Str::uuid()->toString(),
            'document' => strtoupper(Str::random(14)),
        ]);

        $role = $account->roleTemplates()->create([
            'name' => 'Tenant Push Operator',
            'description' => 'Secondary tenant push operator',
            'permissions' => [
                'tenant-push-messages:*',
                'tenant-push-credentials:*',
            ],
        ]);

        $operator = $this->userService->create($account, [
            'name' => 'Secondary Operator',
            'email' => 'secondary-operator-'.$suffix.'@example.org',
            'password' => 'Secret!234',
        ], (string) $role->_id);

        $host = (string) parse_url($tenant->getMainDomain(), PHP_URL_HOST);

        return [$tenant, $operator, $host, $account];
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Zeta', 'subdomain' => 'tenant-zeta'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-zeta.test']
        );

        $service->initialize($payload);
    }
}
