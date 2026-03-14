<?php

declare(strict_types=1);

namespace App\Providers\PackageIntegration;

use App\Integration\Invites\InviteIdentityGatewayAdapter;
use App\Integration\Invites\InviteTargetReadAdapter;
use App\Integration\Invites\InviteTelemetryEmitterAdapter;
use Belluga\Invites\Contracts\InviteIdentityGatewayContract;
use Belluga\Invites\Contracts\InviteTargetReadContract;
use Belluga\Invites\Contracts\InviteTelemetryEmitterContract;
use Illuminate\Support\ServiceProvider;

class InvitesIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            InviteIdentityGatewayContract::class,
            InviteIdentityGatewayAdapter::class
        );

        $this->app->bind(
            InviteTelemetryEmitterContract::class,
            InviteTelemetryEmitterAdapter::class
        );

        $this->app->bind(
            InviteTargetReadContract::class,
            InviteTargetReadAdapter::class
        );
    }
}
