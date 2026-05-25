<?php

declare(strict_types=1);

namespace App\Providers\PackageIntegration;

use App\Application\Social\InviteablePeopleProjectionService;
use App\Application\Push\InvitePushDeliveryService;
use App\Integration\Invites\InviteAttendanceGatewayAdapter;
use App\Integration\Invites\InviteIdentityGatewayAdapter;
use App\Integration\Invites\InviteRecipientProfileProjectionAdapter;
use App\Integration\Invites\InviteTargetReadAdapter;
use App\Integration\Invites\InviteTelemetryEmitterAdapter;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\TenantProfileType;
use Belluga\Favorites\Domain\Events\FavoriteRemoved;
use Belluga\Favorites\Models\Tenants\FavoriteEdge;
use Belluga\Invites\Contracts\InviteAttendanceGatewayContract;
use Belluga\Invites\Contracts\InviteIdentityGatewayContract;
use Belluga\Invites\Contracts\InvitePushDeliveryContract;
use Belluga\Invites\Contracts\InviteRecipientProfileProjectionContract;
use Belluga\Invites\Contracts\InviteTargetReadContract;
use Belluga\Invites\Contracts\InviteTelemetryEmitterContract;
use Illuminate\Support\Facades\Event;
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
            InviteAttendanceGatewayContract::class,
            InviteAttendanceGatewayAdapter::class
        );

        $this->app->bind(
            InviteTelemetryEmitterContract::class,
            InviteTelemetryEmitterAdapter::class
        );

        $this->app->bind(
            InvitePushDeliveryContract::class,
            InvitePushDeliveryService::class
        );

        $this->app->bind(
            InviteTargetReadContract::class,
            InviteTargetReadAdapter::class
        );

        $this->app->bind(
            InviteRecipientProfileProjectionContract::class,
            InviteRecipientProfileProjectionAdapter::class
        );
    }

    public function boot(): void
    {
        Event::listen(
            FavoriteRemoved::class,
            fn (FavoriteRemoved $event): null => $this->refreshInviteablesForFavorite(
                ownerUserId: $event->ownerUserId,
                registryKey: $event->registryKey,
                targetType: $event->targetType,
                targetId: $event->targetId,
            )
        );

        FavoriteEdge::saved(fn (FavoriteEdge $favorite): null => $this->refreshInviteablesForFavoriteEdge($favorite));
        FavoriteEdge::deleted(fn (FavoriteEdge $favorite): null => $this->refreshInviteablesForFavoriteEdge($favorite));

        AccountProfile::saved(fn (AccountProfile $profile): null => $this->projection()->refreshImpactedByProfile($profile));
        AccountProfile::deleted(fn (AccountProfile $profile): null => $this->projection()->refreshImpactedByProfile($profile));
        AccountProfile::restored(fn (AccountProfile $profile): null => $this->projection()->refreshImpactedByProfile($profile));

        AccountUser::saved(fn (AccountUser $user): null => $this->projection()->refreshImpactedByUser($user));
        AccountUser::deleted(fn (AccountUser $user): null => $this->projection()->refreshImpactedByUser($user));

        TenantProfileType::saved(fn (TenantProfileType $type): null => $this->projection()->refreshImpactedByProfileType($type));
        TenantProfileType::deleted(fn (TenantProfileType $type): null => $this->projection()->refreshImpactedByProfileType($type));
    }

    private function refreshInviteablesForFavoriteEdge(FavoriteEdge $favorite): null
    {
        return $this->refreshInviteablesForFavorite(
            ownerUserId: (string) ($favorite->owner_user_id ?? ''),
            registryKey: (string) ($favorite->registry_key ?? ''),
            targetType: (string) ($favorite->target_type ?? ''),
            targetId: (string) ($favorite->target_id ?? ''),
        );
    }

    private function refreshInviteablesForFavorite(
        string $ownerUserId,
        string $registryKey,
        string $targetType,
        string $targetId,
    ): null {
        if ($registryKey === 'account_profile' && $targetType === 'account_profile') {
            $this->projection()->refreshImpactedByFavorite($ownerUserId, $targetId);
        }

        return null;
    }

    private function projection(): InviteablePeopleProjectionService
    {
        return $this->app->make(InviteablePeopleProjectionService::class);
    }
}
