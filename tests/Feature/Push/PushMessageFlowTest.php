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
use Belluga\PushHandler\Models\Tenants\TenantPushSettings;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Belluga\PushHandler\Jobs\SendPushMessageJob;
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

        $this->seedPushSettings();

        $this->baseUrl = sprintf('api/v1/accounts/%s/push/messages', $this->account->slug);
    }

    public function testPushMessageDataRequiresAuth(): void
    {
        $response = $this->getJson($this->baseUrl . '/missing/data');
        $response->assertStatus(401);
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

        $messageId = $create->json('data.id');

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

        $messageId = $create->json('data.id');

        $data = $this->getJson($this->baseUrl . '/' . $messageId . '/data');
        $data->assertStatus(403);
        $data->assertJsonPath('ok', false);
        $data->assertJsonPath('reason', 'forbidden');
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

        $messageId = $create->json('data.id');

        $data = $this->getJson($this->baseUrl . '/' . $messageId . '/data');
        $data->assertOk();
        $data->assertJsonPath('ok', false);
        $data->assertJsonPath('reason', 'inactive');
    }

    public function testPushMessageDataExpiredReturnsOkFalse(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'delivery' => [
                'expires_at' => now()->subDay()->toIso8601String(),
            ],
            'audience' => [
                'type' => 'users',
                'user_ids' => [(string) $this->operator->_id],
            ],
        ]);

        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $messageId = $create->json('data.id');

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

        $messageId = $create->json('data.id');

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
                'expires_at' => now()->addDays(7)->toIso8601String(),
                'scheduled_at' => now()->addDay()->toIso8601String(),
            ],
        ]);

        $create = $this->postJson($this->baseUrl, $payload);
        $create->assertCreated();

        $messageId = $create->json('data.id');

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

        $messageId = $create->json('data.id');

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

        $messageId = $create->json('data.id');

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

    public function testPushMessageSchedulingDispatchesWithDelay(): void
    {
        $this->actingAsOperator();

        Bus::fake();

        $payload = $this->buildPayload([
            'delivery' => [
                'expires_at' => now()->addDays(7)->toIso8601String(),
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
                'allowDismiss' => 'true',
                'steps' => [
                    ['title' => 'Title'],
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
        $response->assertJsonPath('errors.payload_template.buttons.0.action.path_parameters.slug.0', 'Path parameter is required.');
    }

    public function testPushMessageCreateRequiresEventQualifier(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'audience' => [
                'type' => 'event',
                'event_id' => 'event-id',
            ],
        ]);

        $response = $this->postJson($this->baseUrl, $payload);
        $response->assertStatus(422);
        $response->assertJsonPath('errors.audience.event_qualifier.0', 'The audience event qualifier field is required when audience type is event.');
    }

    public function testPushMessageCreateValidatesQueryParams(): void
    {
        $this->actingAsOperator();

        $payload = $this->buildPayload([
            'payload_template' => [
                'layoutType' => 'fullScreen',
                'allowDismiss' => 'true',
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
                                'startSearchActive' => 'not-a-boolean',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->postJson($this->baseUrl, $payload);
        $response->assertStatus(422);
        $response->assertJsonPath(
            'errors.payload_template.buttons.0.action.query_parameters.startSearchActive.0',
            'The start search active field must be true or false.'
        );
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
            'max_ttl_days' => 30,
            'push_message_types' => [
                [
                    'key' => 'invite_received',
                    'label' => 'Invite Received',
                ],
            ],
        ];

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $tenant->subdomain, $this->host);
        $response = $this->patchJson($baseApiTenant . 'settings/push', $payload);
        $response->assertStatus(403);
    }

    public function testTenantPushSettingsUpdateNormalizesRoutes(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, ['push-settings:update']);

        $payload = [
            'max_ttl_days' => 30,
            'push_message_types' => [
                [
                    'key' => 'invite_received',
                    'label' => 'Invite Received',
                ],
            ],
            'push_message_routes' => [
                [
                    'key' => 'agenda.detail',
                    'path' => '/agenda/evento/:slug',
                    'query_params' => [
                        'startWithHistory' => 'boolean',
                    ],
                ],
            ],
            'telemetry' => [
                'mixpanel_token' => 'token',
                'enabled_events' => ['invite_received'],
            ],
            'firebase' => [
                'apiKey' => 'key',
                'appId' => 'app',
                'projectId' => 'project',
                'messagingSenderId' => 'sender',
                'storageBucket' => 'bucket',
            ],
            'push' => [
                'enabled' => true,
                'types' => ['invite_received'],
            ],
        ];

        $baseApiTenant = sprintf('http://%s.%s/api/v1/', $tenant->subdomain, $this->host);
        $response = $this->patchJson($baseApiTenant . 'settings/push', $payload);
        $response->assertOk();
        $response->assertJsonPath('data.push_message_routes.0.path_params.0', 'slug');
    }

    private function actingAsOperator(): void
    {
        Sanctum::actingAs($this->operator, ['push-messages:*', 'push-settings:update']);
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
            'delivery' => [
                'expires_at' => now()->addDays(7)->toIso8601String(),
            ],
            'payload_template' => [
                'layoutType' => 'fullScreen',
                'allowDismiss' => 'true',
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

    private function seedPushSettings(): void
    {
        TenantPushSettings::query()->delete();
        TenantPushSettings::create([
            'max_ttl_days' => 30,
            'push_message_types' => [
                [
                    'key' => 'invite_received',
                    'label' => 'Invite Received',
                ],
            ],
            'push_message_routes' => [
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
        ]);
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
