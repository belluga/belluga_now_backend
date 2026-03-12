<?php

declare(strict_types=1);

namespace Tests\Feature\Ticketing;

use App\Application\Accounts\AccountUserService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Jobs\Ticketing\ExpireIssuedTicketUnitsJob;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Belluga\Settings\Contracts\SettingsRegistryContract;
use Belluga\Settings\Contracts\SettingsStoreContract;
use Belluga\Settings\Support\SettingsNamespaceDefinition;
use Belluga\Ticketing\Models\Tenants\TicketInventoryState;
use Belluga\Ticketing\Models\Tenants\TicketOrder;
use Belluga\Ticketing\Models\Tenants\TicketProduct;
use Belluga\Ticketing\Models\Tenants\TicketPromotion;
use Belluga\Ticketing\Models\Tenants\TicketPromotionRedemption;
use Belluga\Ticketing\Models\Tenants\TicketUnit;
use Belluga\Ticketing\Models\Tenants\TicketUnitAuditEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class TicketingAdmissionFlowTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    private static bool $bootstrapped = false;

    private Account $account;

    private AccountUserService $userService;

    private AccountUser $user;

    private Event $event;

    private EventOccurrence $occurrence;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $tenant = Tenant::query()->where('slug', $this->tenant->slug)->firstOrFail();
        $tenant->makeCurrent();

        Event::query()->delete();
        EventOccurrence::query()->delete();
        TicketProduct::query()->delete();
        TicketInventoryState::query()->delete();
        TicketUnit::query()->delete();
        TicketPromotion::query()->delete();
        TicketPromotionRedemption::query()->delete();
        TicketOrder::query()->delete();
        TicketUnitAuditEvent::query()->delete();

        [$this->account] = $this->seedAccountWithRole(['*']);
        $this->userService = $this->app->make(AccountUserService::class);
        $this->user = $this->createAccountUser(['*']);

        $this->event = Event::query()->create([
            'title' => 'Ticketing Event',
            'slug' => 'ticketing-event-'.Str::random(6),
            'type' => ['id' => 'show', 'name' => 'Show', 'slug' => 'show'],
            'content' => 'Ticketing event content',
            'location' => ['mode' => 'physical'],
            'place_ref' => ['type' => 'venue', 'id' => (string) Str::uuid()],
            'publication' => [
                'status' => 'published',
                'publish_at' => Carbon::now()->subMinute()->toISOString(),
            ],
            'is_active' => true,
            'created_by' => ['type' => 'account_user', 'id' => (string) $this->user->_id],
        ]);

        $this->occurrence = EventOccurrence::query()->create([
            'event_id' => (string) $this->event->_id,
            'occurrence_index' => 0,
            'occurrence_slug' => (string) $this->event->slug.'-occ-1',
            'starts_at' => Carbon::now()->addDay()->setHour(20),
            'ends_at' => Carbon::now()->addDay()->setHour(23),
            'is_event_published' => true,
        ]);

        $this->seedTicketingSettings();
    }

    public function test_offer_endpoint_returns_occurrence_products(): void
    {
        $product = $this->createOccurrenceProduct(capacityTotal: 5, amount: 1200);

        $response = $this->getJson($this->offerUrl());

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'ok');
        $response->assertJsonPath('data.items.0.ticket_product_id', (string) $product->_id);
        $response->assertJsonPath('data.items.0.available', 5);
    }

    public function test_offer_and_admission_support_slug_references(): void
    {
        $product = $this->createOccurrenceProduct(capacityTotal: 2, amount: 900);

        $offer = $this->getJson($this->offerBySlugUrl());
        $offer->assertStatus(200);
        $offer->assertJsonPath('status', 'ok');
        $offer->assertJsonPath('data.items.0.ticket_product_id', (string) $product->_id);

        Sanctum::actingAs($this->user, ['*']);

        $admission = $this->postJson($this->admissionBySlugUrl(), [
            'idempotency_key' => 'adm-slug-'.Str::random(8),
            'checkout_mode' => 'free',
            'items' => [[
                'ticket_product_id' => (string) $product->_id,
                'quantity' => 1,
            ]],
        ]);

        $admission->assertStatus(200);
        $admission->assertJsonPath('status', 'hold_granted');
        $this->assertNotEmpty((string) $admission->json('hold_token'));
    }

    public function test_occurrence_only_offer_and_admission_endpoints_work(): void
    {
        $product = $this->createOccurrenceProduct(capacityTotal: 2, amount: 700);

        $offer = $this->getJson($this->offerOccurrenceOnlyUrl());
        $offer->assertStatus(200);
        $offer->assertJsonPath('status', 'ok');
        $offer->assertJsonPath('data.items.0.ticket_product_id', (string) $product->_id);

        Sanctum::actingAs($this->user, ['*']);

        $admission = $this->postJson($this->admissionOccurrenceOnlyUrl(), [
            'idempotency_key' => 'adm-occ-only-'.Str::random(8),
            'checkout_mode' => 'free',
            'items' => [[
                'ticket_product_id' => (string) $product->_id,
                'quantity' => 1,
            ]],
        ]);

        $admission->assertStatus(200);
        $admission->assertJsonPath('status', 'hold_granted');
    }

    public function test_admission_cart_and_free_checkout_confirm_flow(): void
    {
        $product = $this->createOccurrenceProduct(capacityTotal: 3, amount: 1500);
        Sanctum::actingAs($this->user, ['*']);

        $admission = $this->postJson($this->admissionUrl(), [
            'idempotency_key' => 'adm-'.Str::random(8),
            'checkout_mode' => 'free',
            'items' => [
                [
                    'ticket_product_id' => (string) $product->_id,
                    'quantity' => 2,
                ],
            ],
        ]);

        $admission->assertStatus(200);
        $admission->assertJsonPath('status', 'hold_granted');

        $holdToken = (string) $admission->json('hold_token');

        $cart = $this->getJson($this->cartUrl($holdToken));
        $cart->assertStatus(200);
        $cart->assertJsonPath('status', 'ok');
        $cart->assertJsonPath('data.snapshot.gross_amount', 3000);

        $confirm = $this->postJson($this->confirmUrl(), [
            'hold_token' => $holdToken,
            'idempotency_key' => 'ord-'.Str::random(8),
            'checkout_mode' => 'free',
            'account_id' => (string) $this->account->_id,
        ]);

        $confirm->assertStatus(200);
        $confirm->assertJsonPath('status', 'confirmed');
        $confirm->assertJsonCount(2, 'units');

        $inventory = TicketInventoryState::query()
            ->where('occurrence_id', (string) $this->occurrence->_id)
            ->where('ticket_product_id', (string) $product->_id)
            ->firstOrFail();

        $this->assertSame(0, (int) $inventory->held_count);
        $this->assertSame(2, (int) $inventory->sold_count);
    }

    public function test_admission_returns_queued_when_insufficient_and_queue_is_auto(): void
    {
        $product = $this->createOccurrenceProduct(capacityTotal: 1, amount: 500);
        Sanctum::actingAs($this->user, ['*']);

        $first = $this->postJson($this->admissionUrl(), [
            'idempotency_key' => 'adm-first-'.Str::random(6),
            'checkout_mode' => 'free',
            'items' => [[
                'ticket_product_id' => (string) $product->_id,
                'quantity' => 1,
            ]],
        ]);
        $first->assertStatus(200);
        $first->assertJsonPath('status', 'hold_granted');

        $secondUser = $this->createAccountUser(['*']);
        Sanctum::actingAs($secondUser, ['*']);

        $second = $this->postJson($this->admissionUrl(), [
            'idempotency_key' => 'adm-second-'.Str::random(6),
            'checkout_mode' => 'free',
            'items' => [[
                'ticket_product_id' => (string) $product->_id,
                'quantity' => 1,
            ]],
        ]);

        $second->assertStatus(200);
        $second->assertJsonPath('status', 'queued');
        $second->assertJsonPath('code', 'queue_active');
        $this->assertNotEmpty((string) $second->json('queue_token'));
    }

    public function test_validation_consumes_issued_ticket_unit(): void
    {
        $product = $this->createOccurrenceProduct(capacityTotal: 2, amount: 1000);
        Sanctum::actingAs($this->user, ['*']);

        $admission = $this->postJson($this->admissionUrl(), [
            'idempotency_key' => 'adm-consume-'.Str::random(6),
            'checkout_mode' => 'free',
            'items' => [[
                'ticket_product_id' => (string) $product->_id,
                'quantity' => 1,
            ]],
        ]);

        $holdToken = (string) $admission->json('hold_token');

        $confirm = $this->postJson($this->confirmUrl(), [
            'hold_token' => $holdToken,
            'idempotency_key' => 'ord-consume-'.Str::random(6),
            'checkout_mode' => 'free',
            'account_id' => (string) $this->account->_id,
        ]);

        $unitId = (string) $confirm->json('units.0.ticket_unit_id');

        $validate = $this->postJson($this->validationUrl(), [
            'checkpoint_ref' => 'gate-a',
            'idempotency_key' => 'chk-'.Str::random(6),
            'ticket_unit_id' => $unitId,
        ]);

        $validate->assertStatus(200);
        $validate->assertJsonPath('status', 'consumed');
        $validate->assertJsonPath('code', 'ok');

        $unit = TicketUnit::query()->findOrFail($unitId);
        $this->assertSame('consumed', (string) $unit->lifecycle_state);
    }

    public function test_issued_unit_expires_after_occurrence_lapse_and_is_not_admissible(): void
    {
        $product = $this->createOccurrenceProduct(capacityTotal: 2, amount: 1000);
        Sanctum::actingAs($this->user, ['*']);

        $admission = $this->postJson($this->admissionUrl(), [
            'idempotency_key' => 'adm-expire-'.Str::random(6),
            'checkout_mode' => 'free',
            'items' => [[
                'ticket_product_id' => (string) $product->_id,
                'quantity' => 1,
            ]],
        ]);
        $admission->assertStatus(200);

        $holdToken = (string) $admission->json('hold_token');

        $confirm = $this->postJson($this->confirmUrl(), [
            'hold_token' => $holdToken,
            'idempotency_key' => 'ord-expire-'.Str::random(6),
            'checkout_mode' => 'free',
            'account_id' => (string) $this->account->_id,
        ]);
        $confirm->assertStatus(200);

        $unitId = (string) $confirm->json('units.0.ticket_unit_id');
        $this->occurrence->update([
            'ends_at' => Carbon::now()->subHours(2),
        ]);

        ExpireIssuedTicketUnitsJob::dispatchSync();

        $unit = TicketUnit::query()->findOrFail($unitId);
        $this->assertSame('expired', (string) $unit->lifecycle_state);
        $this->assertNotNull($unit->expired_at);

        $validate = $this->postJson($this->validationUrl(), [
            'checkpoint_ref' => 'gate-b',
            'idempotency_key' => 'chk-expire-'.Str::random(6),
            'ticket_unit_id' => $unitId,
        ]);
        $validate->assertStatus(200);
        $validate->assertJsonPath('status', 'denied');
        $validate->assertJsonPath('code', 'ticket_not_issued');
    }

    public function test_admission_returns_not_applicable_for_unlimited_inventory(): void
    {
        $product = TicketProduct::query()->create([
            'event_id' => (string) $this->event->_id,
            'occurrence_id' => (string) $this->occurrence->_id,
            'scope_type' => 'occurrence',
            'product_type' => 'ticket',
            'status' => 'active',
            'name' => 'Unlimited Access',
            'slug' => 'unlimited-'.Str::random(4),
            'inventory_mode' => 'unlimited',
            'capacity_total' => null,
            'price' => [
                'amount' => 0,
                'currency' => 'BRL',
            ],
            'participant_binding_scope' => 'ticket_unit',
        ]);

        Sanctum::actingAs($this->user, ['*']);

        $admission = $this->postJson($this->admissionUrl(), [
            'idempotency_key' => 'adm-unlimited-'.Str::random(8),
            'checkout_mode' => 'free',
            'items' => [
                [
                    'ticket_product_id' => (string) $product->_id,
                    'quantity' => 1,
                ],
            ],
        ]);

        $admission->assertStatus(200);
        $admission->assertJsonPath('status', 'not_applicable');
        $admission->assertJsonPath('code', 'unlimited_capacity');
    }

    public function test_admission_returns_sold_out_when_queue_policy_is_off(): void
    {
        $product = $this->createOccurrenceProduct(capacityTotal: 1, amount: 500);

        $store = $this->app->make(SettingsStoreContract::class);
        $registry = $this->app->make(SettingsRegistryContract::class);
        $this->mergeSettings($store, $registry, 'ticketing_hold_queue', [
            'policy' => 'off',
            'default_hold_minutes' => 10,
            'max_per_principal' => 10,
        ]);

        Sanctum::actingAs($this->user, ['*']);
        $first = $this->postJson($this->admissionUrl(), [
            'idempotency_key' => 'adm-off-first-'.Str::random(6),
            'checkout_mode' => 'free',
            'items' => [[
                'ticket_product_id' => (string) $product->_id,
                'quantity' => 1,
            ]],
        ]);
        $first->assertStatus(200);
        $first->assertJsonPath('status', 'hold_granted');

        $secondUser = $this->createAccountUser(['*']);
        Sanctum::actingAs($secondUser, ['*']);
        $second = $this->postJson($this->admissionUrl(), [
            'idempotency_key' => 'adm-off-second-'.Str::random(6),
            'checkout_mode' => 'free',
            'items' => [[
                'ticket_product_id' => (string) $product->_id,
                'quantity' => 1,
            ]],
        ]);

        $second->assertStatus(200);
        $second->assertJsonPath('status', 'sold_out');
        $second->assertJsonPath('code', 'admission_required');
    }

    public function test_token_refresh_returns_queue_token_refreshed_without_changing_queue_position(): void
    {
        $product = $this->createOccurrenceProduct(capacityTotal: 1, amount: 500);
        Sanctum::actingAs($this->user, ['*']);

        $first = $this->postJson($this->admissionUrl(), [
            'idempotency_key' => 'adm-refresh-first-'.Str::random(6),
            'checkout_mode' => 'free',
            'items' => [[
                'ticket_product_id' => (string) $product->_id,
                'quantity' => 1,
            ]],
        ]);
        $first->assertStatus(200);
        $first->assertJsonPath('status', 'hold_granted');

        $secondUser = $this->createAccountUser(['*']);
        Sanctum::actingAs($secondUser, ['*']);

        $queued = $this->postJson($this->admissionUrl(), [
            'idempotency_key' => 'adm-refresh-second-'.Str::random(6),
            'checkout_mode' => 'free',
            'items' => [[
                'ticket_product_id' => (string) $product->_id,
                'quantity' => 1,
            ]],
        ]);
        $queued->assertStatus(200);
        $queued->assertJsonPath('status', 'queued');
        $queued->assertJsonPath('position', 1);

        $queueToken = (string) $queued->json('queue_token');

        $refresh = $this->postJson($this->tokenRefreshUrl(), [
            'queue_token' => $queueToken,
        ]);
        $refresh->assertStatus(200);
        $refresh->assertJsonPath('status', 'queued');
        $refresh->assertJsonPath('code', 'queue_token_refreshed');
        $refresh->assertJsonPath('position', 1);
        $this->assertNotSame($queueToken, (string) $refresh->json('queue_token'));
    }

    public function test_token_refresh_returns_hold_non_renewable_signal(): void
    {
        $product = $this->createOccurrenceProduct(capacityTotal: 2, amount: 800);
        Sanctum::actingAs($this->user, ['*']);

        $admission = $this->postJson($this->admissionUrl(), [
            'idempotency_key' => 'adm-hold-refresh-'.Str::random(6),
            'checkout_mode' => 'free',
            'items' => [[
                'ticket_product_id' => (string) $product->_id,
                'quantity' => 1,
            ]],
        ]);
        $admission->assertStatus(200);
        $admission->assertJsonPath('status', 'hold_granted');
        $holdToken = (string) $admission->json('hold_token');

        $refresh = $this->postJson($this->tokenRefreshUrl(), [
            'hold_token' => $holdToken,
        ]);

        $refresh->assertStatus(200);
        $refresh->assertJsonPath('status', 'hold_granted');
        $refresh->assertJsonPath('code', 'hold_token_non_renewable');
        $refresh->assertJsonPath('hold_token', $holdToken);
    }

    public function test_admission_is_rate_limited_by_coarse_guard(): void
    {
        config()->set('belluga_ticketing.rate_limits.admission_per_minute', 1);
        $product = $this->createOccurrenceProduct(capacityTotal: 10, amount: 300);
        Sanctum::actingAs($this->user, ['*']);

        $first = $this->postJson($this->admissionUrl(), [
            'idempotency_key' => 'adm-rate-1-'.Str::random(6),
            'checkout_mode' => 'free',
            'items' => [[
                'ticket_product_id' => (string) $product->_id,
                'quantity' => 1,
            ]],
        ]);
        $first->assertStatus(200);

        $second = $this->postJson($this->admissionUrl(), [
            'idempotency_key' => 'adm-rate-2-'.Str::random(6),
            'checkout_mode' => 'free',
            'items' => [[
                'ticket_product_id' => (string) $product->_id,
                'quantity' => 1,
            ]],
        ]);

        $second->assertStatus(429);
        $second->assertJsonPath('status', 'rejected');
        $second->assertJsonPath('code', 'rate_limited');
    }

    public function test_sse_queue_and_hold_stream_endpoints_return_canonical_envelope(): void
    {
        $product = $this->createOccurrenceProduct(capacityTotal: 1, amount: 1000);
        $offerStream = $this->get($this->offerStreamUrl('occurrence', (string) $this->occurrence->_id), [
            'Accept' => 'text/event-stream',
        ]);
        $offerStream->assertStatus(200);
        $offerContent = $offerStream->streamedContent();
        $this->assertStringContainsString('event: ticketing.v1.offer.occurrence.'.(string) $this->occurrence->_id, $offerContent);
        $this->assertStringContainsString('"event_type":"offer.snapshot"', $offerContent);

        Sanctum::actingAs($this->user, ['*']);

        $admission = $this->postJson($this->admissionUrl(), [
            'idempotency_key' => 'adm-stream-1-'.Str::random(6),
            'checkout_mode' => 'free',
            'items' => [[
                'ticket_product_id' => (string) $product->_id,
                'quantity' => 1,
            ]],
        ]);
        $admission->assertStatus(200);

        $holdId = (string) $admission->json('hold_id');

        $queueUser = $this->createAccountUser(['*']);
        Sanctum::actingAs($queueUser, ['*']);
        $queued = $this->postJson($this->admissionUrl(), [
            'idempotency_key' => 'adm-stream-2-'.Str::random(6),
            'checkout_mode' => 'free',
            'items' => [[
                'ticket_product_id' => (string) $product->_id,
                'quantity' => 1,
            ]],
        ]);
        $queued->assertStatus(200);
        $queued->assertJsonPath('status', 'queued');

        $queueStream = $this->get($this->queueStreamUrl('occurrence', (string) $this->occurrence->_id), [
            'Accept' => 'text/event-stream',
        ]);
        $queueStream->assertStatus(200);
        $queueContent = $queueStream->streamedContent();
        $this->assertStringContainsString('event: ticketing.v1.queue.occurrence.'.(string) $this->occurrence->_id, $queueContent);
        $this->assertStringContainsString('"version":"v1"', $queueContent);
        $this->assertStringContainsString('"event_type":"queue.snapshot"', $queueContent);

        Sanctum::actingAs($this->user, ['*']);
        $holdStream = $this->get($this->holdStreamUrl($holdId), [
            'Accept' => 'text/event-stream',
        ]);
        $holdStream->assertStatus(200);
        $holdContent = $holdStream->streamedContent();
        $this->assertStringContainsString('event: ticketing.v1.hold.'.$holdId, $holdContent);
        $this->assertStringContainsString('"version":"v1"', $holdContent);
        $this->assertStringContainsString('"event_type":"hold.snapshot"', $holdContent);
    }

    public function test_promotions_apply_scope_precedence_and_persist_snapshot_on_confirmation(): void
    {
        $this->enablePromotions();
        $product = $this->createOccurrenceProduct(capacityTotal: 3, amount: 1000);

        TicketPromotion::query()->create([
            'event_id' => (string) $this->event->_id,
            'scope_type' => 'event',
            'code' => 'EVT50',
            'name' => 'Event fixed discount',
            'status' => 'active',
            'type' => 'fixed_discount',
            'mode' => 'stackable',
            'priority' => 200,
            'value' => ['amount' => 50, 'currency' => 'BRL'],
            'redeemed_total' => 0,
            'version' => 1,
        ]);

        TicketPromotion::query()->create([
            'event_id' => (string) $this->event->_id,
            'occurrence_id' => (string) $this->occurrence->_id,
            'ticket_product_id' => (string) $product->_id,
            'scope_type' => 'ticket_product',
            'code' => 'PROD200',
            'name' => 'Product fixed discount',
            'status' => 'active',
            'type' => 'fixed_discount',
            'mode' => 'stackable',
            'priority' => 100,
            'value' => ['amount' => 200, 'currency' => 'BRL'],
            'redeemed_total' => 0,
            'version' => 1,
        ]);

        TicketPromotion::query()->create([
            'event_id' => (string) $this->event->_id,
            'scope_type' => 'event',
            'code' => 'FEE30',
            'name' => 'Service charge',
            'status' => 'active',
            'type' => 'service_charge',
            'mode' => 'stackable',
            'priority' => 300,
            'value' => ['amount' => 30, 'currency' => 'BRL'],
            'redeemed_total' => 0,
            'version' => 1,
        ]);

        Sanctum::actingAs($this->user, ['*']);

        $admission = $this->postJson($this->admissionUrl(), [
            'idempotency_key' => 'adm-promo-'.Str::random(8),
            'checkout_mode' => 'free',
            'promotion_codes' => ['EVT50', 'PROD200', 'FEE30'],
            'items' => [[
                'ticket_product_id' => (string) $product->_id,
                'quantity' => 1,
            ]],
        ]);
        $admission->assertStatus(200);
        $admission->assertJsonPath('status', 'hold_granted');

        $holdToken = (string) $admission->json('hold_token');
        $cart = $this->getJson($this->cartUrl($holdToken));
        $cart->assertStatus(200);
        $cart->assertJsonPath('data.snapshot.gross_amount', 1000);
        $cart->assertJsonPath('data.snapshot.discount_amount', 200);
        $cart->assertJsonPath('data.snapshot.fee_amount', 30);
        $cart->assertJsonPath('data.snapshot.buyer_total', 830);

        $confirm = $this->postJson($this->confirmUrl(), [
            'hold_token' => $holdToken,
            'idempotency_key' => 'ord-promo-'.Str::random(8),
            'checkout_mode' => 'free',
            'account_id' => (string) $this->account->_id,
        ]);
        $confirm->assertStatus(200);
        $confirm->assertJsonPath('status', 'confirmed');
        $confirm->assertJsonPath('financial_snapshot.gross_amount', 1000);
        $confirm->assertJsonPath('financial_snapshot.discount_amount', 200);
        $confirm->assertJsonPath('financial_snapshot.fee_amount', 30);
        $confirm->assertJsonPath('financial_snapshot.buyer_total', 830);
        $confirm->assertJsonPath('promotion_snapshot.totals.discount_amount', 200);
        $confirm->assertJsonPath('promotion_snapshot.totals.fee_amount', 30);

        $order = TicketOrder::query()->findOrFail((string) $confirm->json('order_id'));
        $appliedCodes = collect($order->promotion_snapshot['applied'] ?? [])->pluck('code')->values()->all();
        sort($appliedCodes);
        $this->assertSame(['FEE30', 'PROD200'], $appliedCodes);
        $this->assertSame(2, TicketPromotionRedemption::query()->count());
    }

    public function test_exclusive_promotion_conflict_writes_stable_rejection_reason_in_snapshot(): void
    {
        $this->enablePromotions();
        $product = $this->createOccurrenceProduct(capacityTotal: 3, amount: 1000);

        TicketPromotion::query()->create([
            'event_id' => (string) $this->event->_id,
            'scope_type' => 'event',
            'code' => 'EXC10',
            'name' => 'Exclusive percent',
            'status' => 'active',
            'type' => 'percent_discount',
            'mode' => 'exclusive',
            'priority' => 10,
            'value' => ['percent' => 10, 'currency' => 'BRL'],
            'redeemed_total' => 0,
            'version' => 1,
        ]);

        TicketPromotion::query()->create([
            'event_id' => (string) $this->event->_id,
            'scope_type' => 'event',
            'code' => 'EXCFEE',
            'name' => 'Exclusive fee',
            'status' => 'active',
            'type' => 'service_charge',
            'mode' => 'exclusive',
            'priority' => 20,
            'value' => ['amount' => 40, 'currency' => 'BRL'],
            'redeemed_total' => 0,
            'version' => 1,
        ]);

        Sanctum::actingAs($this->user, ['*']);

        $admission = $this->postJson($this->admissionUrl(), [
            'idempotency_key' => 'adm-exclusive-'.Str::random(8),
            'checkout_mode' => 'free',
            'promotion_codes' => ['EXC10', 'EXCFEE'],
            'items' => [[
                'ticket_product_id' => (string) $product->_id,
                'quantity' => 1,
            ]],
        ]);
        $admission->assertStatus(200);
        $admission->assertJsonPath('status', 'hold_granted');

        $holdToken = (string) $admission->json('hold_token');
        $cart = $this->getJson($this->cartUrl($holdToken));
        $cart->assertStatus(200);
        $cart->assertJsonPath('data.snapshot.discount_amount', 100);
        $cart->assertJsonPath('data.snapshot.fee_amount', 0);
        $cart->assertJsonPath('data.snapshot.promotion_snapshot.applied.0.code', 'EXC10');
        $cart->assertJsonPath('data.snapshot.promotion_snapshot.rejected.0.code', 'EXCFEE');
        $cart->assertJsonPath('data.snapshot.promotion_snapshot.rejected.0.reason_code', 'exclusive_conflict');
    }

    public function test_promotion_quota_prevents_second_confirmation_when_global_limit_is_reached(): void
    {
        $this->enablePromotions();
        $product = $this->createOccurrenceProduct(capacityTotal: 3, amount: 800);

        TicketPromotion::query()->create([
            'event_id' => (string) $this->event->_id,
            'scope_type' => 'event',
            'code' => 'ONCEONLY',
            'name' => 'Single use promotion',
            'status' => 'active',
            'type' => 'fixed_discount',
            'mode' => 'stackable',
            'priority' => 10,
            'value' => ['amount' => 100, 'currency' => 'BRL'],
            'global_uses_limit' => 1,
            'redeemed_total' => 0,
            'version' => 1,
        ]);

        Sanctum::actingAs($this->user, ['*']);
        $firstAdmission = $this->postJson($this->admissionUrl(), [
            'idempotency_key' => 'adm-quota-1-'.Str::random(6),
            'checkout_mode' => 'free',
            'promotion_codes' => ['ONCEONLY'],
            'items' => [[
                'ticket_product_id' => (string) $product->_id,
                'quantity' => 1,
            ]],
        ]);
        $firstAdmission->assertStatus(200);

        $firstConfirm = $this->postJson($this->confirmUrl(), [
            'hold_token' => (string) $firstAdmission->json('hold_token'),
            'idempotency_key' => 'ord-quota-1-'.Str::random(6),
            'checkout_mode' => 'free',
            'account_id' => (string) $this->account->_id,
        ]);
        $firstConfirm->assertStatus(200);
        $firstConfirm->assertJsonPath('status', 'confirmed');

        $secondUser = $this->createAccountUser(['*']);
        Sanctum::actingAs($secondUser, ['*']);
        $secondAdmission = $this->postJson($this->admissionUrl(), [
            'idempotency_key' => 'adm-quota-2-'.Str::random(6),
            'checkout_mode' => 'free',
            'promotion_codes' => ['ONCEONLY'],
            'items' => [[
                'ticket_product_id' => (string) $product->_id,
                'quantity' => 1,
            ]],
        ]);
        $secondAdmission->assertStatus(200);

        $secondConfirm = $this->postJson($this->confirmUrl(), [
            'hold_token' => (string) $secondAdmission->json('hold_token'),
            'idempotency_key' => 'ord-quota-2-'.Str::random(6),
            'checkout_mode' => 'free',
            'account_id' => (string) $this->account->_id,
        ]);
        $secondConfirm->assertStatus(409);
        $secondConfirm->assertJsonPath('status', 'rejected');
        $this->assertContains((string) $secondConfirm->json('code'), [
            'promotion_quota_exhausted',
            'promotion_quota_conflict',
        ]);
        $this->assertSame(1, TicketPromotionRedemption::query()->count());
    }

    public function test_transfer_and_reissue_are_rejected_when_lifecycle_capability_is_disabled(): void
    {
        $product = $this->createOccurrenceProduct(capacityTotal: 2, amount: 1000);
        Sanctum::actingAs($this->user, ['*']);

        $admission = $this->postJson($this->admissionUrl(), [
            'idempotency_key' => 'adm-xfer-disabled-'.Str::random(6),
            'checkout_mode' => 'free',
            'items' => [[
                'ticket_product_id' => (string) $product->_id,
                'quantity' => 1,
            ]],
        ]);
        $admission->assertStatus(200);

        $confirm = $this->postJson($this->confirmUrl(), [
            'hold_token' => (string) $admission->json('hold_token'),
            'idempotency_key' => 'ord-xfer-disabled-'.Str::random(6),
            'checkout_mode' => 'free',
            'account_id' => (string) $this->account->_id,
        ]);
        $confirm->assertStatus(200);
        $ticketUnitId = (string) $confirm->json('units.0.ticket_unit_id');

        $transfer = $this->postJson($this->transferUrl($ticketUnitId), [
            'new_principal_id' => (string) Str::uuid(),
            'reason_code' => 'manual_support',
            'reason_text' => 'Disabled feature check',
            'idempotency_key' => 'xfer-disabled-'.Str::random(6),
        ]);
        $transfer->assertStatus(409);
        $transfer->assertJsonPath('status', 'rejected');
        $transfer->assertJsonPath('code', 'transfer_reissue_disabled');

        $reissue = $this->postJson($this->reissueUrl($ticketUnitId), [
            'new_principal_id' => (string) Str::uuid(),
            'reason_code' => 'manual_support',
            'reason_text' => 'Disabled feature check',
            'idempotency_key' => 'reissue-disabled-'.Str::random(6),
        ]);
        $reissue->assertStatus(409);
        $reissue->assertJsonPath('status', 'rejected');
        $reissue->assertJsonPath('code', 'transfer_reissue_disabled');
    }

    public function test_transfer_creates_replacement_unit_and_invalidates_source_unit(): void
    {
        $this->enableTransferReissue();
        $product = $this->createOccurrenceProduct(capacityTotal: 2, amount: 1100);
        Sanctum::actingAs($this->user, ['*']);

        $admission = $this->postJson($this->admissionUrl(), [
            'idempotency_key' => 'adm-transfer-'.Str::random(6),
            'checkout_mode' => 'free',
            'items' => [[
                'ticket_product_id' => (string) $product->_id,
                'quantity' => 1,
            ]],
        ]);
        $admission->assertStatus(200);

        $confirm = $this->postJson($this->confirmUrl(), [
            'hold_token' => (string) $admission->json('hold_token'),
            'idempotency_key' => 'ord-transfer-'.Str::random(6),
            'checkout_mode' => 'free',
            'account_id' => (string) $this->account->_id,
        ]);
        $confirm->assertStatus(200);
        $sourceUnitId = (string) $confirm->json('units.0.ticket_unit_id');
        $newPrincipalId = (string) Str::uuid();
        $idempotencyKey = 'xfer-'.Str::random(6);

        $transfer = $this->postJson($this->transferUrl($sourceUnitId), [
            'new_principal_id' => $newPrincipalId,
            'reason_code' => 'manual_support',
            'reason_text' => 'Transfer requested',
            'idempotency_key' => $idempotencyKey,
        ]);
        $transfer->assertStatus(200);
        $transfer->assertJsonPath('status', 'ok');
        $transfer->assertJsonPath('operation', 'transfer');
        $transfer->assertJsonCount(1, 'targets');

        $newUnitId = (string) $transfer->json('targets.0.ticket_unit_id');

        $source = TicketUnit::query()->findOrFail($sourceUnitId);
        $this->assertSame('transferred', (string) $source->lifecycle_state);
        $this->assertSame($newUnitId, (string) $source->superseded_by_ticket_unit_id);

        $newUnit = TicketUnit::query()->findOrFail($newUnitId);
        $this->assertSame('issued', (string) $newUnit->lifecycle_state);
        $this->assertSame($newPrincipalId, (string) $newUnit->principal_id);

        $audit = TicketUnitAuditEvent::query()->where('idempotency_key', $idempotencyKey)->first();
        $this->assertNotNull($audit);
        $this->assertSame('transfer', (string) $audit->operation);

        $validation = $this->postJson($this->validationUrl(), [
            'checkpoint_ref' => 'gate-transfer',
            'idempotency_key' => 'chk-transfer-'.Str::random(6),
            'ticket_unit_id' => $sourceUnitId,
        ]);
        $validation->assertStatus(200);
        $validation->assertJsonPath('status', 'denied');
        $validation->assertJsonPath('code', 'ticket_not_issued');
    }

    public function test_reissue_propagates_across_combo_scope_units_and_keeps_atomic_audit_chain(): void
    {
        $this->enableTransferReissue();
        $product = $this->createOccurrenceProduct(capacityTotal: 4, amount: 1300, participantBindingScope: 'combo_unit');
        Sanctum::actingAs($this->user, ['*']);

        $admission = $this->postJson($this->admissionUrl(), [
            'idempotency_key' => 'adm-reissue-combo-'.Str::random(6),
            'checkout_mode' => 'free',
            'items' => [[
                'ticket_product_id' => (string) $product->_id,
                'quantity' => 2,
            ]],
        ]);
        $admission->assertStatus(200);

        $confirm = $this->postJson($this->confirmUrl(), [
            'hold_token' => (string) $admission->json('hold_token'),
            'idempotency_key' => 'ord-reissue-combo-'.Str::random(6),
            'checkout_mode' => 'free',
            'account_id' => (string) $this->account->_id,
        ]);
        $confirm->assertStatus(200);
        $this->assertCount(2, (array) $confirm->json('units'));
        $sourceUnitId = (string) $confirm->json('units.0.ticket_unit_id');
        $sourceOrderItemId = (string) $confirm->json('units.0.order_item_id');
        $idempotencyKey = 'reissue-combo-'.Str::random(6);
        $newPrincipalId = (string) Str::uuid();

        $reissue = $this->postJson($this->reissueUrl($sourceUnitId), [
            'new_principal_id' => $newPrincipalId,
            'reason_code' => 'manual_support',
            'reason_text' => 'Combo reissue',
            'idempotency_key' => $idempotencyKey,
        ]);
        $reissue->assertStatus(200);
        $reissue->assertJsonPath('status', 'ok');
        $reissue->assertJsonPath('operation', 'reissue');
        $reissue->assertJsonPath('scope_binding', 'combo_unit');
        $reissue->assertJsonCount(2, 'targets');

        $oldUnits = TicketUnit::query()
            ->where('order_item_id', $sourceOrderItemId)
            ->where('lifecycle_state', 'reissued')
            ->count();
        $this->assertSame(2, $oldUnits);

        $newUnitIds = collect((array) $reissue->json('targets'))->pluck('ticket_unit_id')->all();
        $newUnits = TicketUnit::query()
            ->whereIn('_id', $newUnitIds)
            ->where('lifecycle_state', 'issued')
            ->where('principal_id', $newPrincipalId)
            ->count();
        $this->assertSame(2, $newUnits);

        $audit = TicketUnitAuditEvent::query()->where('idempotency_key', $idempotencyKey)->first();
        $this->assertNotNull($audit);
        $this->assertSame('reissue', (string) $audit->operation);
        $this->assertSame('combo_unit', (string) $audit->scope_binding);
        $this->assertCount(2, (array) $audit->target_ticket_unit_ids);
    }

    public function test_transfer_endpoints_require_events_update_ability(): void
    {
        $this->enableTransferReissue();
        $product = $this->createOccurrenceProduct(capacityTotal: 2, amount: 700);
        Sanctum::actingAs($this->user, ['*']);

        $admission = $this->postJson($this->admissionUrl(), [
            'idempotency_key' => 'adm-auth-transfer-'.Str::random(6),
            'checkout_mode' => 'free',
            'items' => [[
                'ticket_product_id' => (string) $product->_id,
                'quantity' => 1,
            ]],
        ]);
        $admission->assertStatus(200);
        $confirm = $this->postJson($this->confirmUrl(), [
            'hold_token' => (string) $admission->json('hold_token'),
            'idempotency_key' => 'ord-auth-transfer-'.Str::random(6),
            'checkout_mode' => 'free',
            'account_id' => (string) $this->account->_id,
        ]);
        $confirm->assertStatus(200);
        $ticketUnitId = (string) $confirm->json('units.0.ticket_unit_id');

        $restricted = $this->createAccountUser([]);
        Sanctum::actingAs($restricted, ['events:read']);

        $transfer = $this->postJson($this->transferUrl($ticketUnitId), [
            'new_principal_id' => (string) Str::uuid(),
            'reason_code' => 'manual_support',
            'reason_text' => 'Should be blocked',
            'idempotency_key' => 'xfer-auth-'.Str::random(6),
        ]);
        $transfer->assertStatus(403);
    }

    private function createOccurrenceProduct(int $capacityTotal, int $amount, string $participantBindingScope = 'ticket_unit'): TicketProduct
    {
        return TicketProduct::query()->create([
            'event_id' => (string) $this->event->_id,
            'occurrence_id' => (string) $this->occurrence->_id,
            'scope_type' => 'occurrence',
            'product_type' => 'ticket',
            'status' => 'active',
            'name' => 'General Admission',
            'slug' => 'general-'.Str::random(4),
            'inventory_mode' => 'limited',
            'capacity_total' => $capacityTotal,
            'price' => [
                'amount' => $amount,
                'currency' => 'BRL',
            ],
            'participant_binding_scope' => $participantBindingScope,
        ]);
    }

    private function createAccountUser(array $permissions): AccountUser
    {
        $role = $this->account->roleTemplates()->create([
            'name' => 'Ticketing Role '.Str::random(6),
            'permissions' => $permissions,
        ]);

        return $this->userService->create($this->account, [
            'name' => 'Ticketing User',
            'email' => uniqid('ticketing-user', true).'@example.org',
            'password' => 'Secret!234',
        ], (string) $role->_id);
    }

    private function offerUrl(): string
    {
        return sprintf(
            '%sevents/%s/occurrences/%s/offer',
            $this->base_api_tenant,
            (string) $this->event->_id,
            (string) $this->occurrence->_id,
        );
    }

    private function offerBySlugUrl(): string
    {
        return sprintf(
            '%sevents/%s/occurrences/%s/offer',
            $this->base_api_tenant,
            (string) $this->event->slug,
            (string) $this->occurrence->occurrence_slug,
        );
    }

    private function offerOccurrenceOnlyUrl(): string
    {
        return sprintf(
            '%soccurrences/%s/offer',
            $this->base_api_tenant,
            (string) $this->occurrence->occurrence_slug,
        );
    }

    private function admissionUrl(): string
    {
        return sprintf(
            '%sevents/%s/occurrences/%s/admission',
            $this->base_api_tenant,
            (string) $this->event->_id,
            (string) $this->occurrence->_id,
        );
    }

    private function admissionBySlugUrl(): string
    {
        return sprintf(
            '%sevents/%s/occurrences/%s/admission',
            $this->base_api_tenant,
            (string) $this->event->slug,
            (string) $this->occurrence->occurrence_slug,
        );
    }

    private function admissionOccurrenceOnlyUrl(): string
    {
        return sprintf(
            '%soccurrences/%s/admission',
            $this->base_api_tenant,
            (string) $this->occurrence->occurrence_slug,
        );
    }

    private function validationUrl(): string
    {
        return sprintf(
            '%sevents/%s/occurrences/%s/validation',
            $this->base_api_tenant,
            (string) $this->event->_id,
            (string) $this->occurrence->_id,
        );
    }

    private function cartUrl(string $holdToken): string
    {
        return sprintf('%scheckout/cart?hold_token=%s', $this->base_api_tenant, urlencode($holdToken));
    }

    private function confirmUrl(): string
    {
        return sprintf('%scheckout/confirm', $this->base_api_tenant);
    }

    private function tokenRefreshUrl(): string
    {
        return sprintf('%sadmission/tokens/refresh', $this->base_api_tenant);
    }

    private function transferUrl(string $ticketUnitId): string
    {
        return sprintf(
            '%sevents/%s/occurrences/%s/ticket_units/%s/transfer',
            $this->base_api_tenant,
            (string) $this->event->_id,
            (string) $this->occurrence->_id,
            $ticketUnitId,
        );
    }

    private function reissueUrl(string $ticketUnitId): string
    {
        return sprintf(
            '%sevents/%s/occurrences/%s/ticket_units/%s/reissue',
            $this->base_api_tenant,
            (string) $this->event->_id,
            (string) $this->occurrence->_id,
            $ticketUnitId,
        );
    }

    private function queueStreamUrl(string $scopeType, string $scopeId): string
    {
        return sprintf('%sticketing/streams/queue/%s/%s', $this->base_api_tenant, $scopeType, $scopeId);
    }

    private function holdStreamUrl(string $holdId): string
    {
        return sprintf('%sticketing/streams/hold/%s', $this->base_api_tenant, $holdId);
    }

    private function offerStreamUrl(string $scopeType, string $scopeId): string
    {
        return sprintf('%sticketing/streams/offer/%s/%s', $this->base_api_tenant, $scopeType, $scopeId);
    }

    private function seedTicketingSettings(): void
    {
        $store = $this->app->make(SettingsStoreContract::class);
        $registry = $this->app->make(SettingsRegistryContract::class);

        $this->mergeSettings($store, $registry, 'ticketing_core', [
            'enabled' => true,
            'identity_mode' => 'auth_only',
        ]);

        $this->mergeSettings($store, $registry, 'ticketing_hold_queue', [
            'policy' => 'auto',
            'default_hold_minutes' => 10,
            'max_per_principal' => 10,
        ]);

        $this->mergeSettings($store, $registry, 'checkout_core', [
            'mode' => 'free',
        ]);

        $this->mergeSettings($store, $registry, 'checkout_ticketing', [
            'enabled' => false,
        ]);
    }

    private function enablePromotions(): void
    {
        $store = $this->app->make(SettingsStoreContract::class);
        $registry = $this->app->make(SettingsRegistryContract::class);

        $this->mergeSettings($store, $registry, 'ticketing_promotions', [
            'enabled' => true,
        ]);
    }

    private function enableTransferReissue(): void
    {
        $store = $this->app->make(SettingsStoreContract::class);
        $registry = $this->app->make(SettingsRegistryContract::class);

        $this->mergeSettings($store, $registry, 'ticketing_lifecycle', [
            'allow_transfer_reissue' => true,
        ]);
    }

    private function mergeSettings(
        SettingsStoreContract $store,
        SettingsRegistryContract $registry,
        string $namespace,
        array $changes,
    ): void {
        $definition = $registry->find($namespace, 'tenant');
        if (! $definition instanceof SettingsNamespaceDefinition) {
            $this->fail(sprintf('Missing namespace definition [%s].', $namespace));
        }

        $store->mergeNamespace('tenant', $namespace, $changes, $definition);
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

        $tenant = Tenant::query()->first();
        if ($tenant) {
            $this->landlord->tenant_primary->slug = $tenant->slug;
            $this->landlord->tenant_primary->subdomain = $tenant->subdomain;
            $this->landlord->tenant_primary->id = (string) $tenant->_id;
            $this->landlord->tenant_primary->role_admin->id = (string) ($tenant->roleTemplates()->first()?->_id ?? '');
        }
    }
}
