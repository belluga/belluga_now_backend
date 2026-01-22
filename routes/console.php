<?php

use App\Application\AccountProfiles\AccountProfileRegistrySeeder;
use App\Jobs\PublishScheduledEventsJob;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\TenantSettings;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('tenant:profile-registry:sync-v1 {tenant_slug}', function () {
    $tenantSlug = (string) $this->argument('tenant_slug');

    $tenant = Tenant::query()->where('slug', $tenantSlug)->first();
    if (! $tenant) {
        $this->error("Tenant not found for slug [{$tenantSlug}].");

        return 1;
    }

    $tenant->makeCurrent();

    $registry = (new AccountProfileRegistrySeeder())->defaults();

    $settings = TenantSettings::current();
    if (! $settings) {
        TenantSettings::create([
            'profile_type_registry' => $registry,
        ]);

        $this->info("Profile type registry created for tenant [{$tenantSlug}].");

        return 0;
    }

    $settings->profile_type_registry = $registry;
    $settings->save();

    $this->info("Profile type registry updated for tenant [{$tenantSlug}].");

    return 0;
})->purpose('Overwrite tenant profile_type_registry with V1 defaults (personal/artist/venue only).');

Schedule::job(new PublishScheduledEventsJob())->hourly();
