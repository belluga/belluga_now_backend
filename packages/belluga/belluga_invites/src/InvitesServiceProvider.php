<?php

declare(strict_types=1);

namespace Belluga\Invites;

use Belluga\Invites\Application\Async\InviteOutboxEmitter;
use Belluga\Invites\Application\Contacts\ContactImportService;
use Belluga\Invites\Application\Feed\InviteFeedQueryService;
use Belluga\Invites\Application\Feed\InviteProjectionService;
use Belluga\Invites\Application\Feed\PrincipalSocialMetricsService;
use Belluga\Invites\Application\Mutations\InviteCommandIdempotencyService;
use Belluga\Invites\Application\Mutations\InviteMutationService;
use Belluga\Invites\Application\Mutations\InviteShareService;
use Belluga\Invites\Application\Quotas\InviteQuotaCounterService;
use Belluga\Invites\Application\Realtime\InviteRealtimeStreamService;
use Belluga\Invites\Application\Settings\InviteRuntimeSettingsService;
use Belluga\Invites\Application\Targets\InviteTargetResolverService;
use Belluga\Invites\Application\Transactions\InviteTransactionRunner;
use Belluga\Invites\Contracts\InviteAttendanceGatewayContract;
use Belluga\Invites\Contracts\InviteIdentityGatewayContract;
use Belluga\Invites\Contracts\InviteTargetReadContract;
use Belluga\Invites\Contracts\InviteTelemetryEmitterContract;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class InvitesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InviteRuntimeSettingsService::class);
        $this->app->singleton(InviteTransactionRunner::class);
        $this->app->singleton(InviteOutboxEmitter::class);
        $this->app->singleton(InviteQuotaCounterService::class);
        $this->app->singleton(PrincipalSocialMetricsService::class);
        $this->app->singleton(InviteProjectionService::class);
        $this->app->singleton(InviteTargetResolverService::class);
        $this->app->singleton(InviteFeedQueryService::class);
        $this->app->singleton(InviteCommandIdempotencyService::class);
        $this->app->singleton(InviteMutationService::class);
        $this->app->singleton(InviteShareService::class);
        $this->app->singleton(InviteRealtimeStreamService::class);
        $this->app->singleton(ContactImportService::class);

        $this->ensureHostBinding(InviteIdentityGatewayContract::class);
        $this->ensureHostBinding(InviteAttendanceGatewayContract::class);
        $this->ensureHostBinding(InviteTelemetryEmitterContract::class);
        $this->ensureHostBinding(InviteTargetReadContract::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    private function ensureHostBinding(string $abstract): void
    {
        if ($this->app->bound($abstract)) {
            return;
        }

        $this->app->bind($abstract, static function () use ($abstract) {
            throw new RuntimeException("belluga_invites host binding missing for [{$abstract}]");
        });
    }
}
