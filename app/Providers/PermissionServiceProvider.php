<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\PermissionService;
use Illuminate\Support\ServiceProvider;

class PermissionServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(PermissionService::class, function ($app) {
            return new PermissionService();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Registrar aqui qualquer coisa que precise ser inicializada
    }
}
