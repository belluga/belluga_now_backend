<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\AccountProfiles\AccountProfilePublicCatalogSnapshotReader;
use App\Application\AccountProfiles\AccountProfileTypeSetProvider;
use App\Application\AccountProfiles\Capabilities\AccountProfileCapabilityOverrideProviderContract;
use App\Application\AccountProfiles\Capabilities\AccountProfileCapabilityRegistry;
use App\Application\AccountProfiles\Capabilities\AccountProfileCapabilityResolver;
use App\Application\AccountProfiles\Capabilities\AccountProfileCapabilityResolverContract;
use App\Application\AccountProfiles\Capabilities\HasAvatarCapability;
use App\Application\AccountProfiles\Capabilities\HasBioCapability;
use App\Application\AccountProfiles\Capabilities\HasContactChannelsCapability;
use App\Application\AccountProfiles\Capabilities\HasCoverCapability;
use App\Application\AccountProfiles\Capabilities\HasEventsCapability;
use App\Application\AccountProfiles\Capabilities\HasExternalLinksCapability;
use App\Application\AccountProfiles\Capabilities\HasGalleryCapability;
use App\Application\AccountProfiles\Capabilities\HasNestedProfileGroupsCapability;
use App\Application\AccountProfiles\Capabilities\HasTaxonomiesCapability;
use App\Application\AccountProfiles\Capabilities\IsFavoritableCapability;
use App\Application\AccountProfiles\Capabilities\IsInviteableCapability;
use App\Application\AccountProfiles\Capabilities\IsPubliclyDiscoverableCapability;
use App\Application\AccountProfiles\Capabilities\IsPubliclyNavigableCapability;
use App\Application\AccountProfiles\Capabilities\IsQueryableCapability;
use App\Application\AccountProfiles\Capabilities\LocationPolicyCapability;
use App\Application\AccountProfiles\Capabilities\MapPoiCapability;
use App\Application\AccountProfiles\Capabilities\NullAccountProfileCapabilityOverrideProvider;
use App\Application\AccountProfiles\Capabilities\PhysicalHostCapability;
use App\Application\AccountProfiles\Capabilities\ReferenceLocationCapability;
use App\Application\Media\ExternalImageDnsResolverContract;
use App\Application\Media\SystemExternalImageDnsResolver;
use App\Application\Telemetry\Contracts\TelemetryEmitterContract;
use App\Application\Telemetry\TelemetryEmitter;
use App\Application\Tenants\TenantDomainResolverService;
use App\Application\Tenants\TenantRequestLifecycleTrace;
use App\Auth\Sanctum\RefreshingRequestGuard;
use App\Auth\Sanctum\TracingGuard;
use App\Http\Api\v1\Controllers\ProfileControllerLandlord;
use App\Http\Api\v1\Controllers\ProfileControllerTenant;
use App\Http\Api\v1\Requests\ResetPasswordRequestContract;
use App\Http\Api\v1\Requests\ResetPasswordRequestLandlord;
use App\Http\Api\v1\Requests\ResetPasswordRequestTenant;
use App\Http\Api\v1\Requests\UpdateProfileRequestContract;
use App\Http\Api\v1\Requests\UpdateProfileRequestLandlord;
use App\Http\Api\v1\Requests\UpdateProfileRequestTenant;
use App\Models\Landlord\PersonalAccessToken;
use App\Support\RichText\RichTextReadCanonicalizer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantDomainResolverService::class);
        $this->app->singleton(TenantRequestLifecycleTrace::class);
        $this->app->scoped(AccountProfilePublicCatalogSnapshotReader::class);
        $this->app->scoped(RichTextReadCanonicalizer::class);
        $this->app->bind(AccountProfileTypeSetProvider::class);
        $this->app->singleton(AccountProfileCapabilityRegistry::class, static fn (): AccountProfileCapabilityRegistry => new AccountProfileCapabilityRegistry([
            new IsQueryableCapability,
            new IsPubliclyNavigableCapability,
            new IsPubliclyDiscoverableCapability,
            new IsFavoritableCapability,
            new IsInviteableCapability,
            new HasBioCapability,
            new HasTaxonomiesCapability,
            new HasAvatarCapability,
            new HasCoverCapability,
            new HasEventsCapability,
            new HasGalleryCapability,
            new HasNestedProfileGroupsCapability,
            new HasContactChannelsCapability,
            new HasExternalLinksCapability,
            new LocationPolicyCapability,
            new MapPoiCapability,
            new PhysicalHostCapability,
            new ReferenceLocationCapability,
        ]));
        $this->app->singleton(
            AccountProfileCapabilityOverrideProviderContract::class,
            NullAccountProfileCapabilityOverrideProvider::class,
        );
        $this->app->bind(
            AccountProfileCapabilityResolverContract::class,
            AccountProfileCapabilityResolver::class,
        );

        $this->app->bind(
            ResetPasswordRequestContract::class,
            ResetPasswordRequestLandlord::class
        );

        $this->app->bind(
            UpdateProfileRequestContract::class,
            UpdateProfileRequestLandlord::class
        );

        $this->app->bind(
            TelemetryEmitterContract::class,
            TelemetryEmitter::class
        );

        $this->app->bind(
            ExternalImageDnsResolverContract::class,
            SystemExternalImageDnsResolver::class
        );

        $this->app->when(ProfileControllerLandlord::class)
            ->needs(UpdateProfileRequestContract::class)
            ->give(function ($app) {
                return $app->make(UpdateProfileRequestLandlord::class);
            });

        $this->app->when(ProfileControllerLandlord::class)
            ->needs(ResetPasswordRequestContract::class)
            ->give(function ($app) {
                return $app->make(ResetPasswordRequestLandlord::class);
            });

        $this->app->when(ProfileControllerTenant::class)
            ->needs(UpdateProfileRequestContract::class)
            ->give(function ($app) {
                return $app->make(UpdateProfileRequestTenant::class);
            });

        $this->app->when(ProfileControllerTenant::class)
            ->needs(ResetPasswordRequestContract::class)
            ->give(function ($app) {
                return $app->make(ResetPasswordRequestTenant::class);
            });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        $this->app->booted(function (): void {
            Auth::resolved(function ($auth): void {
                $auth->extend('sanctum', function ($app, $name, array $config) use ($auth) {
                    return tap(new RefreshingRequestGuard(
                        new TracingGuard(
                            $auth,
                            $app->make(TenantRequestLifecycleTrace::class),
                            config('sanctum.expiration'),
                            $config['provider'] ?? null,
                        ),
                        $app['request'],
                        $auth->createUserProvider($config['provider'] ?? null)
                    ), function ($guard) use ($app): void {
                        $app->refresh('request', $guard, 'setRequest');
                    });
                });
            });
        });
    }
}
