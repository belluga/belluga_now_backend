<?php

declare(strict_types=1);

namespace Belluga\Settings;

use Belluga\Settings\Contracts\SettingsMergePolicyContract;
use Belluga\Settings\Contracts\SettingsRegistryContract;
use Belluga\Settings\Contracts\SettingsSchemaValidatorContract;
use Belluga\Settings\Contracts\SettingsStoreContract;
use Belluga\Settings\Merge\NamespacePatchMergePolicy;
use Belluga\Settings\Registry\InMemorySettingsRegistry;
use Belluga\Settings\Stores\MongoSettingsStore;
use Belluga\Settings\Validation\SettingsSchemaValidator;
use Illuminate\Support\ServiceProvider;

class SettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/belluga_settings.php', 'belluga_settings');

        $this->app->singleton(SettingsRegistryContract::class, InMemorySettingsRegistry::class);
        $this->app->singleton(SettingsMergePolicyContract::class, NamespacePatchMergePolicy::class);
        $this->app->singleton(SettingsSchemaValidatorContract::class, SettingsSchemaValidator::class);
        $this->app->singleton(SettingsStoreContract::class, MongoSettingsStore::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/settings.php');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations_landlord');
    }
}
