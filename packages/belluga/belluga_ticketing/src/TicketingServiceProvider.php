<?php

declare(strict_types=1);

namespace Belluga\Ticketing;

use Belluga\Ticketing\Application\Admission\TicketAdmissionService;
use Belluga\Ticketing\Application\Async\TicketOutboxEmitter;
use Belluga\Ticketing\Application\Checkout\CheckoutPayloadAssembler;
use Belluga\Ticketing\Application\Checkout\TicketCheckoutService;
use Belluga\Ticketing\Application\Guards\OccurrenceWriteGuardService;
use Belluga\Ticketing\Application\Holds\TicketHoldService;
use Belluga\Ticketing\Application\Inventory\InventoryMutationService;
use Belluga\Ticketing\Application\Lifecycle\TicketUnitLifecycleService;
use Belluga\Ticketing\Application\Queue\TicketQueueService;
use Belluga\Ticketing\Application\Realtime\TicketRealtimeStreamService;
use Belluga\Ticketing\Application\Security\TicketingCommandRateLimiter;
use Belluga\Ticketing\Application\Settings\TicketingRuntimeSettingsService;
use Belluga\Ticketing\Application\Transactions\TenantTransactionRunner;
use Belluga\Settings\Contracts\SettingsRegistryContract;
use Belluga\Settings\Support\SettingsNamespaceDefinition;
use Belluga\Ticketing\Contracts\CheckoutOrchestratorContract;
use Belluga\Ticketing\Contracts\EventTemplateReadContract;
use Belluga\Ticketing\Contracts\OccurrencePublicationContract;
use Belluga\Ticketing\Contracts\OccurrenceReadContract;
use Belluga\Ticketing\Contracts\TicketingPolicyContract;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class TicketingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OccurrenceWriteGuardService::class);
        $this->app->singleton(TicketingRuntimeSettingsService::class);
        $this->app->singleton(TenantTransactionRunner::class);
        $this->app->singleton(InventoryMutationService::class);
        $this->app->singleton(TicketQueueService::class);
        $this->app->singleton(TicketRealtimeStreamService::class);
        $this->app->singleton(TicketingCommandRateLimiter::class);
        $this->app->singleton(TicketOutboxEmitter::class);
        $this->app->singleton(TicketHoldService::class);
        $this->app->singleton(CheckoutPayloadAssembler::class);
        $this->app->singleton(TicketAdmissionService::class);
        $this->app->singleton(TicketCheckoutService::class);
        $this->app->singleton(TicketUnitLifecycleService::class);

        $this->ensureHostBinding(OccurrenceReadContract::class);
        $this->ensureHostBinding(OccurrencePublicationContract::class);
        $this->ensureHostBinding(EventTemplateReadContract::class);
        $this->ensureHostBinding(CheckoutOrchestratorContract::class);
        $this->ensureHostBinding(TicketingPolicyContract::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/ticketing.php');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->registerTicketingSettingsNamespaces();
    }

    private function ensureHostBinding(string $abstract): void
    {
        if ($this->app->bound($abstract)) {
            return;
        }

        $this->app->bind($abstract, static function () use ($abstract) {
            throw new RuntimeException("belluga_ticketing host binding missing for [{$abstract}]");
        });
    }

    private function registerTicketingSettingsNamespaces(): void
    {
        if (! $this->app->bound(SettingsRegistryContract::class)) {
            return;
        }

        /** @var SettingsRegistryContract $registry */
        $registry = $this->app->make(SettingsRegistryContract::class);
        $ability = 'ticketing-settings:update';

        $registry->register(new SettingsNamespaceDefinition(
            namespace: 'ticketing_core',
            scope: 'tenant',
            label: 'Ticketing Core',
            groupLabel: 'Ticketing',
            ability: $ability,
            fields: [
                'enabled' => [
                    'type' => 'boolean',
                    'nullable' => false,
                    'label' => 'Enabled',
                    'default' => false,
                    'order' => 10,
                ],
                'identity_mode' => [
                    'type' => 'string',
                    'nullable' => false,
                    'label' => 'Identity Mode',
                    'default' => 'auth_only',
                    'options' => [
                        ['value' => 'auth_only', 'label' => 'Auth Only'],
                        ['value' => 'guest_or_auth', 'label' => 'Guest or Auth'],
                    ],
                    'order' => 20,
                ],
            ],
            order: 120,
            labelI18nKey: 'settings.ticketing_core.namespace.label',
            description: 'Ticketing core toggles and identity policies.',
            descriptionI18nKey: 'settings.ticketing_core.namespace.description',
            icon: 'confirmation_number',
        ));

        $registry->register(new SettingsNamespaceDefinition(
            namespace: 'ticketing_hold_queue',
            scope: 'tenant',
            label: 'Ticketing Hold and Queue',
            groupLabel: 'Ticketing',
            ability: $ability,
            fields: [
                'policy' => [
                    'type' => 'string',
                    'nullable' => false,
                    'label' => 'Queue Policy',
                    'default' => 'auto',
                    'options' => [
                        ['value' => 'auto', 'label' => 'Auto'],
                        ['value' => 'off', 'label' => 'Off'],
                    ],
                    'order' => 10,
                ],
                'default_hold_minutes' => [
                    'type' => 'integer',
                    'nullable' => false,
                    'label' => 'Default Hold Minutes',
                    'default' => 10,
                    'order' => 20,
                ],
                'max_per_principal' => [
                    'type' => 'integer',
                    'nullable' => false,
                    'label' => 'Max Per Principal',
                    'default' => 10,
                    'order' => 30,
                ],
            ],
            order: 130,
            labelI18nKey: 'settings.ticketing_hold_queue.namespace.label',
            description: 'Queue policy and hold window defaults.',
            descriptionI18nKey: 'settings.ticketing_hold_queue.namespace.description',
            icon: 'hourglass_top',
        ));

        $registry->register(new SettingsNamespaceDefinition(
            namespace: 'ticketing_seating',
            scope: 'tenant',
            label: 'Ticketing Seating',
            groupLabel: 'Ticketing',
            ability: $ability,
            fields: [
                'enabled' => [
                    'type' => 'boolean',
                    'nullable' => false,
                    'label' => 'Enabled',
                    'default' => false,
                    'order' => 10,
                ],
            ],
            order: 140,
            labelI18nKey: 'settings.ticketing_seating.namespace.label',
            description: 'Seat-map capability toggle (vnext-delivered).',
            descriptionI18nKey: 'settings.ticketing_seating.namespace.description',
            icon: 'event_seat',
        ));

        $registry->register(new SettingsNamespaceDefinition(
            namespace: 'ticketing_validation',
            scope: 'tenant',
            label: 'Ticketing Validation',
            groupLabel: 'Ticketing',
            ability: $ability,
            fields: [
                'enforce_occurrence_scope' => [
                    'type' => 'boolean',
                    'nullable' => false,
                    'label' => 'Enforce Occurrence Scope',
                    'default' => true,
                    'order' => 10,
                ],
            ],
            order: 150,
            labelI18nKey: 'settings.ticketing_validation.namespace.label',
            description: 'Admission validation policy toggles.',
            descriptionI18nKey: 'settings.ticketing_validation.namespace.description',
            icon: 'verified',
        ));

        $registry->register(new SettingsNamespaceDefinition(
            namespace: 'ticketing_security',
            scope: 'tenant',
            label: 'Ticketing Security',
            groupLabel: 'Ticketing',
            ability: $ability,
            fields: [
                'idempotency_window_seconds' => [
                    'type' => 'integer',
                    'nullable' => false,
                    'label' => 'Idempotency Window (seconds)',
                    'default' => 300,
                    'order' => 10,
                ],
            ],
            order: 160,
            labelI18nKey: 'settings.ticketing_security.namespace.label',
            description: 'Security baseline for idempotent commands.',
            descriptionI18nKey: 'settings.ticketing_security.namespace.description',
            icon: 'shield',
        ));

        $registry->register(new SettingsNamespaceDefinition(
            namespace: 'ticketing_lifecycle',
            scope: 'tenant',
            label: 'Ticketing Lifecycle',
            groupLabel: 'Ticketing',
            ability: $ability,
            fields: [
                'allow_transfer_reissue' => [
                    'type' => 'boolean',
                    'nullable' => false,
                    'label' => 'Allow Transfer and Reissue',
                    'default' => false,
                    'order' => 10,
                ],
                'issued_expiry_grace_minutes' => [
                    'type' => 'integer',
                    'nullable' => false,
                    'label' => 'Issued Expiry Grace Minutes',
                    'default' => 30,
                    'order' => 20,
                ],
            ],
            order: 170,
            labelI18nKey: 'settings.ticketing_lifecycle.namespace.label',
            description: 'Lifecycle capability toggles for transfer and reissue.',
            descriptionI18nKey: 'settings.ticketing_lifecycle.namespace.description',
            icon: 'autorenew',
        ));

        $registry->register(new SettingsNamespaceDefinition(
            namespace: 'checkout_core',
            scope: 'tenant',
            label: 'Checkout Core',
            groupLabel: 'Checkout',
            ability: $ability,
            fields: [
                'mode' => [
                    'type' => 'string',
                    'nullable' => false,
                    'label' => 'Mode',
                    'default' => 'free',
                    'options' => [
                        ['value' => 'free', 'label' => 'Free'],
                        ['value' => 'paid', 'label' => 'Paid'],
                    ],
                    'order' => 10,
                ],
            ],
            order: 180,
            labelI18nKey: 'settings.checkout_core.namespace.label',
            description: 'Checkout runtime mode for ticketing handoff.',
            descriptionI18nKey: 'settings.checkout_core.namespace.description',
            icon: 'shopping_cart',
        ));

        $registry->register(new SettingsNamespaceDefinition(
            namespace: 'checkout_ticketing',
            scope: 'tenant',
            label: 'Checkout Ticketing',
            groupLabel: 'Checkout',
            ability: $ability,
            fields: [
                'enabled' => [
                    'type' => 'boolean',
                    'nullable' => false,
                    'label' => 'Enabled',
                    'default' => false,
                    'order' => 10,
                ],
            ],
            order: 190,
            labelI18nKey: 'settings.checkout_ticketing.namespace.label',
            description: 'Checkout integration toggle for ticketing paid flows.',
            descriptionI18nKey: 'settings.checkout_ticketing.namespace.description',
            icon: 'payments',
        ));

        $registry->register(new SettingsNamespaceDefinition(
            namespace: 'participation_presence',
            scope: 'tenant',
            label: 'Participation Presence',
            groupLabel: 'Participation',
            ability: $ability,
            fields: [
                'write_mode' => [
                    'type' => 'string',
                    'nullable' => false,
                    'label' => 'Write Mode',
                    'default' => 'canonical',
                    'options' => [
                        ['value' => 'canonical', 'label' => 'Canonical'],
                    ],
                    'order' => 10,
                ],
            ],
            order: 200,
            labelI18nKey: 'settings.participation_presence.namespace.label',
            description: 'Canonical participation presence write contract settings.',
            descriptionI18nKey: 'settings.participation_presence.namespace.description',
            icon: 'check_circle',
        ));

        $registry->register(new SettingsNamespaceDefinition(
            namespace: 'participation_proofs',
            scope: 'tenant',
            label: 'Participation Proofs',
            groupLabel: 'Participation',
            ability: $ability,
            fields: [
                'enabled' => [
                    'type' => 'boolean',
                    'nullable' => false,
                    'label' => 'Enabled',
                    'default' => true,
                    'order' => 10,
                ],
                'proof_types' => [
                    'type' => 'array',
                    'nullable' => false,
                    'label' => 'Proof Types',
                    'default' => ['qr'],
                    'order' => 20,
                ],
            ],
            order: 210,
            labelI18nKey: 'settings.participation_proofs.namespace.label',
            description: 'Admission proof verification settings.',
            descriptionI18nKey: 'settings.participation_proofs.namespace.description',
            icon: 'qr_code_2',
        ));
    }
}
