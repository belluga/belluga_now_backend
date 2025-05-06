<?php

namespace App\Providers;

use App\Models\Landlord\PersonalAccessToken;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use MongoDB\Laravel\Eloquent\Model;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\AccountSessionManager::class, function ($app) {
            return new \App\Services\AccountSessionManager();
        });

        $this->app->singleton(\App\Services\TenantSessionManager::class, function ($app) {
            return new \App\Services\TenantSessionManager();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::unguard();
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    }
}
