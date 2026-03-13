<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Media\ExternalImageDnsResolverContract;
use App\Application\Media\SystemExternalImageDnsResolver;
use App\Application\Telemetry\Contracts\TelemetryEmitterContract;
use App\Application\Telemetry\TelemetryEmitter;
use App\Application\Tenants\TenantDomainResolverService;
use App\Http\Api\v1\Controllers\ProfileControllerLandlord;
use App\Http\Api\v1\Controllers\ProfileControllerTenant;
use App\Http\Api\v1\Requests\ResetPasswordRequestContract;
use App\Http\Api\v1\Requests\ResetPasswordRequestLandlord;
use App\Http\Api\v1\Requests\ResetPasswordRequestTenant;
use App\Http\Api\v1\Requests\UpdateProfileRequestContract;
use App\Http\Api\v1\Requests\UpdateProfileRequestLandlord;
use App\Http\Api\v1\Requests\UpdateProfileRequestTenant;
use App\Models\Landlord\PersonalAccessToken;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantDomainResolverService::class, function ($app) {
            return new TenantDomainResolverService;
        });

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
    }
}
