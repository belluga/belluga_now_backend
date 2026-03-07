<?php

declare(strict_types=1);

namespace Tests\Feature\Push;

use App\Application\Accounts\AccountUserService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountRoleTemplate;
use App\Models\Tenants\AccountUser;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Belluga\PushHandler\Models\Tenants\PushCredential;
use Belluga\PushHandler\Models\Tenants\PushDeliveryLog;
use Belluga\PushHandler\Models\Tenants\TenantPushSettings;
use Belluga\PushHandler\Services\FcmHttpV1Client;
use Belluga\PushHandler\Contracts\FcmClientContract;
use Belluga\PushHandler\Contracts\PushPlanPolicyDecisionContract;
use Belluga\PushHandler\Services\PushDeviceService;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Belluga\PushHandler\Jobs\SendPushMessageJob;
use Belluga\PushHandler\Contracts\PushAudienceEligibilityContract;
use Belluga\PushHandler\Contracts\PushPlanPolicyContract;
use Belluga\PushHandler\Services\PushDeliveryService;
use Belluga\PushHandler\Services\PushRecipientResolver;
use Belluga\Settings\Models\Tenants\TenantSettings;
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
            return new class implements PushAudienceEligibilityContract {
                public function isEligible(
                    Authenticatable $user,
                    PushMessage $message,
                    array $audience,
                    array $context = []
                ): bool {
                    if (! $user instanceof AccountUser) {
                        return false;
                    }

                    $type = $audience['type'] ?? 'all';
                    if ($type === 'users') {
                        $ids = $audience['user_ids'] ?? [];
                        return in_array((string) $user->_id, $ids, true);
                    }

                    return true;
                }
            };
        });

        $this->seedPushSettings();

        $tenant = Tenant::query()->firstOrFail();
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
        PushDeliveryLog::query()->delete();
        TenantSettings::query()->delete();
    }

    public function testPushMessageDataRequiresAuth(): void
    {
        $response = $this->getJson($this->baseUrl . '/missing/data');
        $response->assertStatus(401);
    }

    public function testPushMessageDataMissingReturnsOkFalse(): void
    {
        $this->actingAsOperator();

        $missingId = (string) new \MongoDB\BSON\ObjectId();

        $data = $this->getJson($this->baseUrl . '/' . $missingId . '/data');
        $data->assertOk();
        $data->assertJsonPath('ok', false);
        $data->assertJsonPath('reason', 'not_found');
    }

    public function testPushMessageCreateAndFetchData(): void
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
        $data = $this->getJson($this->baseUrl . '/' . $messageId . '/data');
        $data->assertOk();
        $data->assertJsonPath('ok', true);
        $data->assertJsonPath('push_message_id', $messageId);
    }

    public function testPushMessageDataForbiddenWhenNotInAudience(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'users',
                'user_ids' => [Str::uuid()->toString()],
            ],
        ]);

        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $data = $this->getJson($this->baseUrl . '/' . $messageId . '/data');
        $data->assertStatus(404);
        $data->assertJsonPath('ok', false);
        $data->assertJsonPath('reason', 'not_found');
    }

    public function testPushMessageDataInactiveReturnsOkFalse(): void
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

        $data = $this->getJson($this->baseUrl . '/' . $messageId . '/data');
        $data->assertOk();
        $data->assertJsonPath('ok', false);
        $data->assertJsonPath('reason', 'inactive');
    }

    public function testPushMessageDataExpiredReturnsOkFalse(): void
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

        $data = $this->getJson($this->baseUrl . '/' . $messageId . '/data');
        $data->assertOk();
        $data->assertJsonPath('ok', false);
        $data->assertJsonPath('reason', 'expired');
    }

    public function testPushMessageDeleteArchivesWhenSent(): void
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

        $delete = $this->deleteJson($this->baseUrl . '/' . $messageId);
        $delete->assertOk();
        $delete->assertJsonPath('data.status', 'archived');
        $delete->assertJsonPath('data.active', false);
    }

    public function testPushMessageDeleteHardWhenScheduled(): void
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

        $delete = $this->deleteJson($this->baseUrl . '/' . $messageId);
        $delete->assertOk();
        $delete->assertJsonPath('ok', true);
    }

    public function testPushMessageActionsRecordMetrics(): void
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
            'idempotency_key' => 'click:' . $messageId,
        ];

        $action = $this->postJson($this->baseUrl . '/' . $messageId . '/actions', $actionPayload);
        $action->assertOk();

        $duplicate = $this->postJson($this->baseUrl . '/' . $messageId . '/actions', $actionPayload);
        $duplicate->assertOk();

        $message = PushMessage::query()->find($messageId);
        $this->assertNotNull($message);
        $metrics = $message->metrics ?? [];
        $this->assertEquals(1, $metrics['clicked_count'] ?? 0);
        $this->assertEquals(1, $metrics['unique_clicked_count'] ?? 0);
    }

    public function testPushMessageActionsRecordOpenedMetrics(): void
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

        $action = $this->postJson($this->baseUrl . '/' . $messageId . '/actions', [
            'action' => 'opened',
            'step_index' => 0,
            'idempotency_key' => 'opened:' . $messageId,
        ]);

        $action->assertOk();

        $message = PushMessage::query()->find($messageId);
        $this->assertNotNull($message);
        $metrics = $message->metrics ?? [];
        $this->assertEquals(1, $metrics['opened_count'] ?? 0);
        $this->assertEquals(1, $metrics['unique_opened_count'] ?? 0);
    }

    public function testPushMessageActionsRecordDismissedMetrics(): void
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

        $action = $this->postJson($this->baseUrl . '/' . $messageId . '/actions', [
            'action' => 'dismissed',
            'step_index' => 0,
            'idempotency_key' => 'dismissed:' . $messageId,
        ]);

        $action->assertOk();

        $message = PushMessage::query()->find($messageId);
        $this->assertNotNull($message);
        $metrics = $message->metrics ?? [];
        $this->assertEquals(1, $metrics['dismissed_count'] ?? 0);
        $this->assertEquals(1, $metrics['unique_dismissed_count'] ?? 0);
    }

    public function testPushMessageActionsRecordStepViewedMetrics(): void
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

        $action = $this->postJson($this->baseUrl . '/' . $messageId . '/actions', [
            'action' => 'step_viewed',
            'step_index' => 1,
            'idempotency_key' => 'step_viewed:' . $messageId,
        ]);

        $action->assertOk();

        $message = PushMessage::query()->find($messageId);
        $this->assertNotNull($message);
        $metrics = $message->metrics ?? [];
        $this->assertEquals(1, $metrics['step_view_counts'][1] ?? 0);
    }

    public function testPushMessageActionsRecordDeliveredMetrics(): void
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

        $action = $this->postJson($this->baseUrl . '/' . $messageId . '/actions', [
            'action' => 'delivered',
            'step_index' => 0,
            'idempotency_key' => 'delivered:' . $messageId,
        ]);

        $action->assertOk();

        $message = PushMessage::query()->find($messageId);
        $this->assertNotNull($message);
        $metrics = $message->metrics ?? [];
        $this->assertEquals(1, $metrics['delivered_count'] ?? 0);
    }

    public function testPushMessageActionsRequireStepIndex(): void
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

        $action = $this->postJson($this->baseUrl . '/' . $messageId . '/actions', [
            'action' => 'opened',
            'idempotency_key' => 'opened-missing-step:' . $messageId,
        ]);

        $action->assertStatus(422);
        $action->assertJsonValidationErrors(['step_index']);
    }

    public function testPushMessageActionsClickedRequiresButtonKey(): void
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

        $action = $this->postJson($this->baseUrl . '/' . $messageId . '/actions', [
            'action' => 'clicked',
            'step_index' => 0,
            'idempotency_key' => 'clicked-missing-button:' . $messageId,
        ]);

        $action->assertStatus(422);
        $action->assertJsonValidationErrors(['button_key']);
    }

    public function testPushMessageActionsForbiddenWhenNotEligible(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'users',
                'user_ids' => [Str::uuid()->toString()],
            ],
        ]);

        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $action = $this->postJson($this->baseUrl . '/' . $messageId . '/actions', [
            'action' => 'opened',
            'step_index' => 0,
            'idempotency_key' => 'opened:' . $messageId,
        ]);

        $action->assertStatus(403);
        $action->assertJsonPath('reason', 'forbidden');
    }

    public function testPushMessageActionsRequireAuth(): void
    {
        $response = $this->postJson($this->baseUrl . '/missing/actions', [
            'action' => 'opened',
            'step_index' => 0,
            'idempotency_key' => 'opened:missing',
        ]);

        $response->assertStatus(401);
    }

    public function testPushMessageListAndUpdate(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload();
        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $list = $this->getJson($this->baseUrl);
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));

        $update = $this->patchJson($this->baseUrl . '/' . $messageId, [
            'body_template' => 'Updated body',
        ]);
        $update->assertOk();
        $update->assertJsonPath('data.body_template', 'Updated body');
    }

    public function testPushMessageListRequiresTenantAccess(): void
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

    public function testAccountPushCrudRequiresAuth(): void
    {
        $this->withServerVariables([
            'HTTP_HOST' => $this->tenantHost,
        ]);

        $list = $this->getJson($this->baseUrl);
        $list->assertStatus(401);

        $create = $this->postJson($this->baseUrl, $this->buildPayload());
        $create->assertStatus(401);
    }

    public function testAccountPushCreateRequiresAbility(): void
    {
        $this->withServerVariables([
            'HTTP_HOST' => $this->tenantHost,
        ]);
        Sanctum::actingAs($this->operator, ['push-messages:read']);

        $create = $this->postJson($this->baseUrl, $this->buildPayload());
        $create->assertStatus(403);
    }

    public function testTenantPushCrudRequiresAuth(): void
    {
        $list = $this->getJson('api/v1/push/messages');
        $list->assertStatus(401);

        $create = $this->postJson('api/v1/push/messages', $this->buildPayload());
        $create->assertStatus(401);
    }

    public function testTenantPushCreateRequiresAbility(): void
    {
        Sanctum::actingAs($this->operator, ['tenant-push-messages:read']);

        $create = $this->postJson('api/v1/push/messages', $this->buildPayload());
        $create->assertStatus(403);
    }

    public function testTenantPushListRequiresTenantAccess(): void
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

    public function testTenantCrossTenantDataAndActionsReturnNotFound(): void
    {
        $primaryTenant = Tenant::query()->where('subdomain', 'tenant-zeta')->firstOrFail();

        [$secondaryTenant, $secondaryOperator, $secondaryHost] = $this->seedSecondaryTenantContext();

        $payload = $this->buildPayload();
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

        $data = $this->getJson('api/v1/push/messages/' . $messageId . '/data');
        $data->assertOk();
        $data->assertJsonPath('ok', false);
        $data->assertJsonPath('reason', 'not_found');

        $action = $this->postJson('api/v1/push/messages/' . $messageId . '/actions', [
            'action' => 'opened',
            'step_index' => 0,
            'idempotency_key' => 'opened:' . $messageId,
        ]);
        $action->assertStatus(404);

        $secondaryTenant->forgetCurrent();
    }

    public function testTenantCrossTenantCrudReturnsNotFound(): void
    {
        $primaryTenant = Tenant::query()->where('subdomain', 'tenant-zeta')->firstOrFail();

        [$secondaryTenant, $secondaryOperator, $secondaryHost] = $this->seedSecondaryTenantContext();

        $payload = $this->buildPayload();
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

        $show = $this->getJson('api/v1/push/messages/' . $messageId);
        $show->assertStatus(404);

        $secondaryTenant->forgetCurrent();
    }

    public function testTenantCrossTenantCredentialUpsertIsTenantScoped(): void
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
        $update = $this->putJson($baseApiTenant . 'settings/push/credentials', [
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

    public function testPushMessageSchedulingDispatchesWithDelay(): void
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

    public function testPushMessageCreateValidatesRouteParams(): void
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

    public function testPushMessageCreateAllowsEventAudienceWithoutQualifier(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'event',
                'event_id' => 'event-id',
            ],
        ]);

        $response = $this->postJson($this->baseUrl, $payload);
        $response->assertCreated();
    }

    public function testPushMessageCreateValidatesQueryParams(): void
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

    public function testPushMessageCreateRequiresCoreTemplates(): void
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

    public function testPushMessageCreateRequiresSteps(): void
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

    public function testPushMessageCreateRequiresStepContent(): void
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

    public function testPushMessageCreateAcceptsImageOnlyStep(): void
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

    public function testPushMessageCreateSanitizesHtmlBody(): void
    {
        $this->actingAsOperator();

        $body = '<p>Hello <strong>World</strong><script>alert(1)</script>'
            . '<span style="color: #ff0000; font-weight: 700; font-size: 18px; background: blue;">Hi</span>'
            . '<img src="javascript:alert(1)" />'
            . '<img src="https://example.com/hero.png" width="120" height="80" onclick="nope" />'
            . '<ul><li>One</li></ul>'
            . '</p>';

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

    public function testPushMessageCreateRequiresCloseBehavior(): void
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

    public function testPushMessageUpdateRejectsCloseOnLastStepAction(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload();
        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $update = $this->patchJson($this->baseUrl . '/' . $messageId, [
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

    public function testPushMessageCreateRejectsNonTextQuestions(): void
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

    public function testPushMessageCreateRejectsSelectionModeOnQuestions(): void
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

    public function testPushMessageCreateDefaultsSelectorSelectionMode(): void
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

    public function testPushMessageCreatePersistsPayloadTemplateDisplayFields(): void
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

    public function testPushMessageUpdatePersistsPayloadTemplateDisplayFields(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload();
        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $update = $this->patchJson($this->baseUrl . '/' . $messageId, [
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

    public function testPushMessageCreateRequiresAudienceType(): void
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

    public function testPushMessageCreateUsersAudienceRequiresUserIds(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'users',
            ],
        ]);

        $response = $this->postJson($this->baseUrl, $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'audience.user_ids',
        ]);
    }

    public function testPushMessageCreateRejectsDeliveryExpiresAt(): void
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

    public function testPushMessageCreateRejectsPastDeadline(): void
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

    public function testTenantPushSettingsUpdateRequiresTenantAccess(): void
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
        $response = $this->patchJson($baseApiTenant . 'settings/push', $payload);
        $response->assertStatus(403);
    }

    public function testTenantPushSettingsRequiresPushConfig(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, ['push-settings:update']);

        $payload = [];

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $tenant->subdomain, $this->host);
        $response = $this->patchJson($baseApiTenant . 'settings/push', $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'payload',
        ]);
    }

    public function testTenantPushSettingsDefaultsMaxTtlDays(): void
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
        $response = $this->patchJson($baseApiTenant . 'settings/push', $payload);
        $response->assertOk();
        $response->assertJsonPath('data.max_ttl_days', 7);
    }

    public function testTenantPushSettingsPatchIsVisibleInKernelValuesEndpoint(): void
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
        $patch = $this->patchJson($baseApiTenant . 'settings/push', $payload);
        $patch->assertOk();
        $patch->assertJsonPath('data.max_ttl_days', 21);
        $patch->assertJsonPath('data.throttles.per_minute', 120);

        $kernelValues = $this->getJson(str_replace('/api/v1/', '/admin/api/v1/', $baseApiTenant) . 'settings/values');
        $kernelValues->assertOk();
        $kernelValues->assertJsonPath('data.push.max_ttl_days', 21);
        $kernelValues->assertJsonPath('data.push.throttles.per_minute', 120);
    }

    public function testTenantFirebaseSettingsUpdateRequiresTenantAccess(): void
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
        $response = $this->patchJson($baseApiTenant . 'settings/firebase', $payload);
        $response->assertStatus(403);
    }

    public function testTenantFirebaseSettingsRequiresFirebaseConfig(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, ['push-settings:update']);

        $payload = [];

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $tenant->subdomain, $this->host);
        $response = $this->patchJson($baseApiTenant . 'settings/firebase', $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'payload',
        ]);
    }

    public function testTenantFirebaseSettingsPatchIsVisibleInKernelValuesEndpoint(): void
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
        $patch = $this->patchJson($baseApiTenant . 'settings/firebase', $payload);
        $patch->assertOk();
        $patch->assertJsonPath('data.projectId', 'tenant-project');

        $kernelValues = $this->getJson(str_replace('/api/v1/', '/admin/api/v1/', $baseApiTenant) . 'settings/values');
        $kernelValues->assertOk();
        $kernelValues->assertJsonPath('data.firebase.projectId', 'tenant-project');
        $kernelValues->assertJsonPath('data.firebase.apiKey', 'tenant-key');
    }

    public function testLandlordTenantFirebaseSettingsAdminEndpointsUseKernelNamespace(): void
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

    public function testTenantRouteTypesUpdateNormalizesRoutes(): void
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
        $response = $this->patchJson($baseApiTenant . 'settings/push/route_types', $payload);
        $response->assertOk();
        $response->assertJsonFragment([
            'key' => 'agenda.detail',
            'path_params' => ['slug'],
            'query_params' => [
                'event_id' => 'string',
            ],
        ]);

        $kernelValues = $this->getJson(str_replace('/api/v1/', '/admin/api/v1/', $baseApiTenant) . 'settings/values');
        $kernelValues->assertOk();
        $routes = $kernelValues->json('data.push.message_routes');
        $this->assertIsArray($routes);
        $detail = collect($routes)->firstWhere('key', 'agenda.detail');
        $this->assertIsArray($detail);
        $this->assertSame('/agenda/evento/:slug', $detail['path'] ?? null);
    }

    public function testTenantPushSettingsRejectsRouteAndTypeFields(): void
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

        $response = $this->patchJson($baseApiTenant . 'settings/push', $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'firebase',
            'telemetry',
            'push_message_routes',
            'push_message_types',
            'push',
        ]);
    }

    public function testTenantRouteTypesPatchMergesByKey(): void
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

        $response = $this->patchJson($baseApiTenant . 'settings/push/route_types', $payload);
        $response->assertOk();
        $response->assertJsonFragment(['key' => 'agenda.search', 'path' => '/agenda/search']);
        $response->assertJsonFragment(['key' => 'agenda.detail', 'path' => '/agenda/evento/:slug']);
        $response->assertJsonFragment(['key' => 'agenda.new', 'path' => '/agenda/new']);

        $kernelValues = $this->getJson(str_replace('/api/v1/', '/admin/api/v1/', $baseApiTenant) . 'settings/values');
        $kernelValues->assertOk();
        $routes = $kernelValues->json('data.push.message_routes');
        $this->assertIsArray($routes);
        $this->assertNotNull(collect($routes)->firstWhere('key', 'agenda.new'));
    }

    public function testTenantMessageTypesPatchMergesByKey(): void
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

        $response = $this->patchJson($baseApiTenant . 'settings/push/message_types', $payload);
        $response->assertOk();
        $response->assertJsonFragment(['key' => 'invite_received', 'label' => 'Invite Updated']);
        $response->assertJsonFragment(['key' => 'event_reminder', 'label' => 'Event Reminder']);
        $response->assertJsonFragment(['key' => 'new_type', 'label' => 'New Type']);

        $kernelValues = $this->getJson(str_replace('/api/v1/', '/admin/api/v1/', $baseApiTenant) . 'settings/values');
        $kernelValues->assertOk();
        $types = $kernelValues->json('data.push.message_types');
        $this->assertIsArray($types);
        $updated = collect($types)->firstWhere('key', 'invite_received');
        $this->assertIsArray($updated);
        $this->assertSame('Invite Updated', $updated['label'] ?? null);
    }

    public function testTenantRouteTypesSoftDeleteByKey(): void
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

        $response = $this->deleteJson($baseApiTenant . 'settings/push/route_types', $payload);
        $response->assertOk();
        $response->assertJsonFragment(['key' => 'agenda.search', 'path' => '/agenda']);
        $response->assertJsonFragment(['key' => 'agenda.detail', 'active' => false]);

        $kernelValues = $this->getJson(str_replace('/api/v1/', '/admin/api/v1/', $baseApiTenant) . 'settings/values');
        $kernelValues->assertOk();
        $routes = $kernelValues->json('data.push.message_routes');
        $this->assertIsArray($routes);
        $deleted = collect($routes)->firstWhere('key', 'agenda.detail');
        $this->assertIsArray($deleted);
        $this->assertFalse((bool) ($deleted['active'] ?? true));
    }

    public function testTenantMessageTypesSoftDeleteByKey(): void
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

        $response = $this->deleteJson($baseApiTenant . 'settings/push/message_types', $payload);
        $response->assertOk();
        $response->assertJsonFragment(['key' => 'invite_received', 'label' => 'Invite Received']);
        $response->assertJsonFragment(['key' => 'event_reminder', 'active' => false]);

        $kernelValues = $this->getJson(str_replace('/api/v1/', '/admin/api/v1/', $baseApiTenant) . 'settings/values');
        $kernelValues->assertOk();
        $types = $kernelValues->json('data.push.message_types');
        $this->assertIsArray($types);
        $deleted = collect($types)->firstWhere('key', 'event_reminder');
        $this->assertIsArray($deleted);
        $this->assertFalse((bool) ($deleted['active'] ?? true));
    }

    public function testInactiveRouteTypeRejectedWhenCreatingMessage(): void
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

    public function testInactiveMessageTypeBlocksRouteFiltering(): void
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

    public function testPushMessageCreateRejectsRouteKeyNotAllowedForType(): void
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

    public function testTenantPushStatusNotConfigured(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();
        TenantPushSettings::query()->delete();

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, ['push-settings:update']);

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $tenant->subdomain, $this->host);
        $response = $this->getJson($baseApiTenant . 'settings/push/status');
        $response->assertOk();
        $response->assertJsonPath('status', 'not_configured');
    }

    public function testTenantPushStatusPendingTests(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();
        PushDeliveryLog::query()->delete();
        $this->seedPushSettings();

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, ['push-settings:update']);

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $tenant->subdomain, $this->host);
        $enable = $this->postJson($baseApiTenant . 'settings/push/enable');
        $enable->assertOk();
        $response = $this->getJson($baseApiTenant . 'settings/push/status');
        $response->assertOk();
        $response->assertJsonPath('status', 'pending_tests');
    }

    public function testTenantPushStatusActive(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();
        PushDeliveryLog::query()->delete();
        $this->seedPushSettings();

        PushDeliveryLog::create([
            'push_message_id' => (string) new \MongoDB\BSON\ObjectId(),
            'batch_id' => 'batch-1',
            'token_hash' => 'token',
            'status' => 'accepted',
        ]);

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, ['push-settings:update']);

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $tenant->subdomain, $this->host);
        $enable = $this->postJson($baseApiTenant . 'settings/push/enable');
        $enable->assertOk();
        $response = $this->getJson($baseApiTenant . 'settings/push/status');
        $response->assertOk();
        $response->assertJsonPath('status', 'active');
    }

    public function testTenantTelemetryAddRemoveEnforcesUniqueTypes(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();
        $this->seedTelemetrySettings([]);

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, ['telemetry-settings:update']);

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $tenant->subdomain, $this->host);

        $response = $this->postJson($baseApiTenant . 'settings/telemetry', [
            'type' => 'mixpanel',
            'token' => 'token',
            'events' => ['invite_received'],
        ]);
        $response->assertOk();
        $response->assertJsonPath('data.0.type', 'mixpanel');
        $payload = $response->json();
        $this->assertContains('invite_received', $payload['available_events'] ?? []);

        $response = $this->postJson($baseApiTenant . 'settings/telemetry', [
            'type' => 'mixpanel',
            'token' => 'token-updated',
            'events' => ['invite_received'],
        ]);
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.token', 'token-updated');

        $response = $this->postJson($baseApiTenant . 'settings/telemetry', [
            'type' => 'webhook',
            'url' => 'https://example.org/hook',
            'events' => ['invite_received'],
        ]);
        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $payload = $response->json();
        $this->assertContains('invite_received', $payload['available_events'] ?? []);

        $response = $this->deleteJson($baseApiTenant . 'settings/telemetry/mixpanel');
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.type', 'webhook');
    }

    public function testTenantTelemetryAcceptsTrackAllWithoutEvents(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();
        $this->seedTelemetrySettings([]);

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, ['telemetry-settings:update']);

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $tenant->subdomain, $this->host);

        $response = $this->postJson($baseApiTenant . 'settings/telemetry', [
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

    public function testTenantPushEnableRequiresConfig(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, ['push-settings:update']);

        TenantPushSettings::query()->delete();

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $tenant->subdomain, $this->host);
        $response = $this->postJson($baseApiTenant . 'settings/push/enable');
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['firebase', 'push']);
    }

    public function testTenantPushEnableSetsEnabledTrue(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, ['push-settings:update']);

        TenantPushSettings::query()->delete();
        TenantPushSettings::create($this->buildTenantSettingsPayload());

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $tenant->subdomain, $this->host);
        $response = $this->postJson($baseApiTenant . 'settings/push/enable');
        $response->assertOk();
        $response->assertJsonPath('data.enabled', true);
    }

    public function testTenantPushDisableSetsEnabledFalse(): void
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
        $response = $this->postJson($baseApiTenant . 'settings/push/disable');
        $response->assertOk();
        $response->assertJsonPath('data.enabled', false);
    }

    public function testPlanPolicyBlocksDispatchWhenCannotSend(): void
    {
        $this->actingAsOperator();

        Bus::fake();

        $this->app->bind(PushPlanPolicyContract::class, static function () {
            return new class implements PushPlanPolicyContract {
                public function canSend(string $accountId, PushMessage $message, int $audienceSize): bool
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

    public function testCreateReturnsQuotaDecisionWhenPolicyProvides(): void
    {
        $this->actingAsOperator();

        Bus::fake();

        $this->app->bind(PushPlanPolicyContract::class, static function () {
            return new class implements PushPlanPolicyContract, PushPlanPolicyDecisionContract {
                public function canSend(string $accountId, PushMessage $message, int $audienceSize): bool
                {
                    return true;
                }

                public function quotaDecision(string $accountId, PushMessage $message, int $audienceSize): array
                {
                    return [
                        'allowed' => true,
                        'limit' => 100,
                        'current_used' => 10,
                        'requested' => $audienceSize,
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

    public function testQuotaCheckReturnsDecision(): void
    {
        Sanctum::actingAs($this->operator, ['push-messages:send']);

        $response = $this->getJson(sprintf(
            'api/v1/accounts/%s/push/quota-check?audience_size=5',
            $this->account->slug
        ));

        $response->assertOk();
        $response->assertJsonPath('allowed', true);
    }

    public function testQuotaCheckInvalidInputReturns422(): void
    {
        Sanctum::actingAs($this->operator, ['push-messages:send']);

        $response = $this->getJson(sprintf(
            'api/v1/accounts/%s/push/quota-check',
            $this->account->slug
        ));

        $response->assertStatus(422);
    }

    public function testFcmOptionsInvalidKeyReturns422(): void
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

    public function testFcmOptionsDataSizeLimitReturns422(): void
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

    public function testFcmOptionsNotificationTitleTooLongReturns422(): void
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

    public function testFcmOptionsNotificationBodyTooLongReturns422(): void
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

    public function testExternalActionMissingUrlReturns422(): void
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

    public function testExternalActionInvalidOpenModeReturns422(): void
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

    public function testTenantPushMessageCrudWorks(): void
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

        $update = $this->patchJson('api/v1/push/messages/' . $messageId, [
            'body_template' => 'Tenant update',
        ]);
        $update->assertOk();
        $update->assertJsonPath('data.body_template', 'Tenant update');
    }

    public function testTenantMessageDataForbiddenWhenNotEligible(): void
    {
        Sanctum::actingAs($this->operator, [
            'tenant-push-messages:create',
            'tenant-push-messages:read',
        ]);

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'users',
                'user_ids' => [Str::uuid()->toString()],
            ],
        ]);

        $create = $this->postJson('api/v1/push/messages', $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $data = $this->getJson('api/v1/push/messages/' . $messageId . '/data');
        $data->assertStatus(404);
        $data->assertJsonPath('reason', 'not_found');
    }

    public function testTenantMessageActionsForbiddenWhenNotEligible(): void
    {
        Sanctum::actingAs($this->operator, [
            'tenant-push-messages:create',
            'tenant-push-messages:read',
        ]);

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'users',
                'user_ids' => [Str::uuid()->toString()],
            ],
        ]);

        $create = $this->postJson('api/v1/push/messages', $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $action = $this->postJson('api/v1/push/messages/' . $messageId . '/actions', [
            'action' => 'opened',
            'step_index' => 0,
            'idempotency_key' => 'opened:' . $messageId,
        ]);

        $action->assertStatus(403);
        $action->assertJsonPath('reason', 'forbidden');
    }

    public function testAudienceEligibilityContractDenyBlocksData(): void
    {
        $this->actingAsOperator();

        $this->app->bind(PushAudienceEligibilityContract::class, static function () {
            return new class implements PushAudienceEligibilityContract {
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
                'type' => 'all',
            ],
        ]);

        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $data = $this->getJson($this->baseUrl . '/' . $messageId . '/data');
        $data->assertStatus(404);
        $data->assertJsonPath('reason', 'not_found');
    }

    public function testAudienceEligibilityContractOverrideAllowsData(): void
    {
        $this->actingAsOperator();

        $this->app->bind(PushAudienceEligibilityContract::class, static function () {
            return new class implements PushAudienceEligibilityContract {
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
                'user_ids' => [Str::uuid()->toString()],
            ],
        ]);

        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $messageId = $this->resolveMessageId($payload['internal_name']);

        $data = $this->getJson($this->baseUrl . '/' . $messageId . '/data');
        $data->assertOk();
        $data->assertJsonPath('ok', true);
    }

    public function testTransactionalSendRequiresTransactionalType(): void
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

        $send = $this->postJson($this->baseUrl . '/' . $messageId . '/send', [
            'user_id' => (string) $this->operator->_id,
        ]);

        $send->assertStatus(422);
    }

    public function testTenantCredentialsEndpointsRequirePermission(): void
    {
        Sanctum::actingAs($this->operator, ['tenant-push-credentials:read']);

        $response = $this->putJson('api/v1/settings/push/credentials', [
            'project_id' => 'project-id',
            'client_email' => 'client@example.org',
            'private_key' => 'secret',
        ]);

        $response->assertStatus(403);
    }

    public function testTenantCredentialUpsertCreatesAndUpdatesSingleRecord(): void
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

    public function testTenantCredentialsIndexReturnsWithoutPrivateKey(): void
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

    public function testTenantCredentialsIndexReturnsConflictWhenMultiple(): void
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

    public function testTenantCredentialsUpsertReturnsConflictWhenMultiple(): void
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

    public function testTenantPushStatusReturnsConflictWhenMultipleCredentials(): void
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
        $enable = $this->postJson($baseApiTenant . 'settings/push/enable');
        $enable->assertOk();
        $response = $this->getJson($baseApiTenant . 'settings/push/status');
        $response->assertStatus(409);
    }

    public function testTenantCredentialValidationReturns422(): void
    {
        Sanctum::actingAs($this->operator, ['tenant-push-credentials:update']);

        $response = $this->putJson('api/v1/settings/push/credentials', [
            'project_id' => 'project-id',
            'client_email' => 'client@example.org',
        ]);

        $response->assertStatus(422);
    }

    public function testTenantSettingsDoesNotExposeFirebaseCredentialsId(): void
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
        $response = $this->patchJson($baseApiTenant . 'settings/firebase', $payload);
        $response->assertOk();
        $response->assertJsonMissing(['firebase_credentials_id']);
    }

    public function testDeliveryLogsHaveNoTtlIndex(): void
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

    public function testDeliveryServiceLogsPartialFailures(): void
    {
        $this->app->bind(FcmClientContract::class, static function () {
            return new class implements FcmClientContract {
                public function send(
                    PushMessage $message,
                    array $tokens,
                    string $messageInstanceId,
                    Carbon $expiresAt,
                    int $ttlMinutes
                ): array
                {
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

    public function testDeliveryServiceCapsExpiresAtToDeadline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $this->app->bind(FcmClientContract::class, static function () {
            return new class implements FcmClientContract {
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

    public function testDeliveryServiceRejectsPastDeadline(): void
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

    public function testDeliveryServiceRejectsTtlBeyondMax(): void
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

    public function testDeliveryServiceBatchesTokensByConfig(): void
    {
        config(['belluga_push_handler.fcm.max_batch_size' => 500]);

        $batches = [];
        $this->app->bind(FcmClientContract::class, function () use (&$batches) {
            return new class($batches) implements FcmClientContract {
                /**
                 * @param array<int, int> $batches
                 */
                public function __construct(private array &$batches)
                {
                }

                public function send(
                    PushMessage $message,
                    array $tokens,
                    string $messageInstanceId,
                    Carbon $expiresAt,
                    int $ttlMinutes
                ): array
                {
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
            $tokens[] = 'token-' . $i;
        }

        $response = $service->deliver($message, $tokens);

        $this->assertSame([500, 500, 200], $batches);
        $this->assertSame(1200, $response['accepted_count']);
    }

    public function testSendJobUpdatesAcceptedMetricsFromFcmResponse(): void
    {
        $this->app->bind(FcmClientContract::class, static function () {
            return new class implements FcmClientContract {
                public function send(
                    PushMessage $message,
                    array $tokens,
                    string $messageInstanceId,
                    Carbon $expiresAt,
                    int $ttlMinutes
                ): array
                {
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
            return new class extends \Belluga\PushHandler\Services\PushRecipientResolver {
                public function __construct()
                {
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
            $this->app->make(\Belluga\PushHandler\Services\PushRecipientResolver::class)
        );

        $message->refresh();
        $this->assertSame(2, $message->metrics['accepted_count'] ?? null);
        $this->assertSame(1, $message->metrics['sent_count'] ?? null);
        $this->assertSame('sent', $message->status);
        $this->assertNotNull($message->sent_at);
    }

    public function testFcmHttpClientBuildsPayloadWithOverrides(): void
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

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'token'], 200),
            'https://fcm.googleapis.com/v1/projects/project-id/messages:send' => Http::response(['name' => 'msg-1'], 200),
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
        Http::assertSent(function ($request) use ($expiresAt) {
            if ($request->url() !== 'https://fcm.googleapis.com/v1/projects/project-id/messages:send') {
                return false;
            }
            $payload = $request->data()['message'] ?? [];
            return ($payload['notification']['title'] ?? null) === 'Override title'
                && ($payload['data']['custom'] ?? null) === 'value'
                && isset($payload['data']['push_message_id'])
                && isset($payload['data']['message_instance_id'])
                && ($payload['android']['ttl'] ?? null) === '600s'
                && ($payload['webpush']['headers']['TTL'] ?? null) === '600'
                && (string) ($payload['apns']['headers']['apns-expiration'] ?? '') === (string) $expiresAt->getTimestamp();
        });

        Carbon::setTestNow();
    }

    public function testFcmHttpClientHonorsPlatformOverrides(): void
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

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'token'], 200),
            'https://fcm.googleapis.com/v1/projects/project-id/messages:send' => Http::response(['name' => 'msg-1'], 200),
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

        Http::assertSent(function ($request) use ($expiresAt) {
            if ($request->url() !== 'https://fcm.googleapis.com/v1/projects/project-id/messages:send') {
                return false;
            }

            $payload = $request->data()['message'] ?? [];

            return ($payload['notification']['title'] ?? null) === 'Default title'
                && ($payload['notification']['body'] ?? null) === 'Default body'
                && ($payload['android']['notification']['title'] ?? null) === 'Android title'
                && ($payload['apns']['payload']['aps']['alert']['title'] ?? null) === 'Apns title'
                && ($payload['apns']['payload']['aps']['alert']['body'] ?? null) === 'Apns body'
                && isset($payload['data']['push_message_id'])
                && isset($payload['data']['message_instance_id'])
                && ($payload['android']['ttl'] ?? null) === '900s'
                && ($payload['webpush']['headers']['TTL'] ?? null) === '900'
                && (string) ($payload['apns']['headers']['apns-expiration'] ?? '') === (string) $expiresAt->getTimestamp();
        });

        Carbon::setTestNow();
    }

    public function testQuotaCheckBlockedReturnsReason(): void
    {
        $this->app->bind(PushPlanPolicyContract::class, static function () {
            return new class implements PushPlanPolicyContract, PushPlanPolicyDecisionContract {
                public function canSend(string $accountId, PushMessage $message, int $audienceSize): bool
                {
                    return false;
                }

                public function quotaDecision(string $accountId, PushMessage $message, int $audienceSize): array
                {
                    return [
                        'allowed' => false,
                        'limit' => 10,
                        'current_used' => 10,
                        'requested' => $audienceSize,
                        'remaining_after' => 0,
                        'period' => 'monthly',
                        'reason' => 'quota_exceeded',
                    ];
                }
            };
        });

        Sanctum::actingAs($this->operator, ['push-messages:send']);

        $response = $this->getJson(sprintf(
            'api/v1/accounts/%s/push/quota-check?audience_size=5',
            $this->account->slug
        ));

        $response->assertOk();
        $response->assertJsonPath('allowed', false);
        $response->assertJsonPath('reason', 'quota_exceeded');
    }

    public function testTransactionalSendAcceptsEmailTarget(): void
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

        AccountUser::query()->where('_id', $this->operator->_id)->update([
            'devices' => [
                [
                    'device_id' => 'device-1',
                    'push_token' => 'token-1',
                ],
            ],
        ]);

        $send = $this->postJson($this->baseUrl . '/' . $messageId . '/send', [
            'email' => 'push-operator@example.org',
            'dry_run' => true,
        ]);

        $send->assertOk();
        $send->assertJsonPath('ok', true);
    }

    public function testTransactionalSendAcceptsUserIdTarget(): void
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

        AccountUser::query()->where('_id', $this->operator->_id)->update([
            'devices' => [
                [
                    'device_id' => 'device-1',
                    'push_token' => 'token-1',
                ],
            ],
        ]);

        $send = $this->postJson($this->baseUrl . '/' . $messageId . '/send', [
            'user_id' => (string) $this->operator->_id,
            'dry_run' => true,
        ]);

        $send->assertOk();
        $send->assertJsonPath('ok', true);
    }

    public function testRegisterUpdatesDeviceTokenAndReactivates(): void
    {
        $user = AccountUser::query()->where('_id', $this->operator->_id)->firstOrFail();
        $user->devices = [
            [
                'device_id' => 'device-1',
                'platform' => 'android',
                'push_token' => 'token-old',
                'is_active' => false,
                'invalidated_at' => new UTCDateTime(),
            ],
        ];
        $user->save();

        $service = $this->app->make(PushDeviceService::class);
        $service->register($user, [
            'device_id' => 'device-1',
            'platform' => 'android',
            'push_token' => 'token-new',
        ]);

        $user->refresh();
        $this->assertCount(1, $user->devices ?? []);
        $device = $user->devices[0];
        $this->assertSame('token-new', $device['push_token'] ?? null);
        $this->assertTrue($device['is_active'] ?? false);
        $this->assertNull($device['invalidated_at'] ?? null);
    }

    public function testInvalidateTokensMarksInactiveAndKeepsOthers(): void
    {
        $user = AccountUser::query()->where('_id', $this->operator->_id)->firstOrFail();
        $user->devices = [
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
        ];
        $user->save();

        $service = $this->app->make(PushDeviceService::class);
        $service->invalidateTokens($user, ['token-1']);

        $user->refresh();
        $devices = collect($user->devices ?? []);
        $device1 = $devices->firstWhere('device_id', 'device-1');
        $device2 = $devices->firstWhere('device_id', 'device-2');

        $this->assertSame(false, $device1['is_active'] ?? null);
        $this->assertNotNull($device1['invalidated_at'] ?? null);
        $this->assertTrue(($device2['is_active'] ?? true) === true);
    }

    public function testRecipientResolverSkipsInactiveTokens(): void
    {
        $user = AccountUser::query()->where('_id', $this->operator->_id)->firstOrFail();
        $user->devices = [
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
        ];
        $user->save();

        $resolver = $this->app->make(PushRecipientResolver::class);
        $tokens = $resolver->tokensForUser($user);

        $this->assertSame(['token-active'], $tokens);
    }

    public function testInviteReceivedTelemetryUsesUserIdDistinctId(): void
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
            return new class implements FcmClientContract {
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

    public function testInviteReceivedTelemetryTracksAllWithoutEventsList(): void
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
            return new class implements FcmClientContract {
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

    public function testSendInvalidatesNotFoundTokensAndSkipsOnNextSend(): void
    {
        $this->actingAsOperator();

        $this->app->bind(FcmClientContract::class, static function () {
            return new class implements FcmClientContract {
                public function send(
                    PushMessage $message,
                    array $tokens,
                    string $messageInstanceId,
                    Carbon $expiresAt,
                    int $ttlMinutes
                ): array
                {
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

        AccountUser::query()->where('_id', $this->operator->_id)->update([
            'devices' => [
                [
                    'device_id' => 'device-1',
                    'push_token' => 'token-1',
                    'platform' => 'android',
                ],
            ],
        ]);

        $send = $this->postJson($this->baseUrl . '/' . $messageId . '/send', [
            'user_id' => (string) $this->operator->_id,
        ]);
        $send->assertOk();

        $user = AccountUser::query()->where('_id', $this->operator->_id)->firstOrFail();
        $device = collect($user->devices ?? [])->firstWhere('device_id', 'device-1');
        $this->assertSame(false, $device['is_active'] ?? null);

        $retry = $this->postJson($this->baseUrl . '/' . $messageId . '/send', [
            'user_id' => (string) $this->operator->_id,
            'dry_run' => true,
        ]);
        $retry->assertStatus(422);
        $retry->assertJsonPath('reason', 'no_tokens');
    }

    public function testTransactionalSendDeniedWhenEligibilityFails(): void
    {
        $this->actingAsOperator();

        $this->app->bind(PushAudienceEligibilityContract::class, static function () {
            return new class implements PushAudienceEligibilityContract {
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

        $send = $this->postJson($this->baseUrl . '/' . $messageId . '/send', [
            'email' => 'push-operator@example.org',
            'dry_run' => true,
        ]);

        $send->assertStatus(403);
        $send->assertJsonPath('reason', 'forbidden');
    }

    public function testSendReturnsInactiveWhenScopeMismatch(): void
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

        $send = $this->postJson('api/v1/push/messages/' . $messageId . '/send', [
            'user_id' => (string) $this->operator->_id,
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
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function buildPayload(array $overrides = []): array
    {
        $payload = [
            'internal_name' => 'message-' . Str::uuid()->toString(),
            'title_template' => 'Hello {{user_name}}',
            'body_template' => 'Body text',
            'type' => 'invite_received',
            'active' => true,
            'audience' => [
                'type' => 'all',
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
     * @param array<int, array<string, mixed>> $trackers
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

    /**
     * @return array{0: Tenant, 1: AccountUser, 2: string}
     */
    private function seedSecondaryTenantContext(): array
    {
        $suffix = Str::lower(Str::random(6));
        $tenant = Tenant::create([
            'name' => 'Tenant Secondary',
            'subdomain' => 'tenant-secondary-' . $suffix,
            'app_domains' => ['tenant-secondary-' . $suffix . '.app'],
            'domains' => [],
        ]);

        $tenant->makeCurrent();
        $this->seedPushSettings();

        $account = Account::create([
            'name' => 'Account Secondary ' . Str::uuid()->toString(),
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
            'email' => 'secondary-operator-' . $suffix . '@example.org',
            'password' => 'Secret!234',
        ], (string) $role->_id);

        $host = (string) parse_url($tenant->getMainDomain(), PHP_URL_HOST);

        return [$tenant, $operator, $host];
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
