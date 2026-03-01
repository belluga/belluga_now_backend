<?php

use App\Application\AccountProfiles\AccountProfileRegistrySeeder;
use App\Jobs\Ticketing\ExpireIssuedTicketUnitsJob;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\TenantSettings;
use App\Models\Tenants\TenantProfileType;
use Belluga\Events\Application\Events\EventOccurrenceReconciliationService;
use Belluga\Events\Application\Operations\EventAsyncOperationsMonitorService;
use Belluga\Events\Jobs\PublishScheduledEventsJob;
use Belluga\Ticketing\Jobs\ProcessTicketOutboxJob;
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

    TenantProfileType::query()->delete();
    foreach ($registry as $entry) {
        TenantProfileType::create($entry);
    }

    $this->info("Profile type registry updated for tenant [{$tenantSlug}].");

    return 0;
})->purpose('Overwrite tenant profile_type_registry with V1 defaults (personal/artist/venue only).');

Schedule::job(new PublishScheduledEventsJob())->hourly();

Schedule::call(static function (): void {
    app(EventAsyncOperationsMonitorService::class)->evaluate();
})
    ->name('events:async:monitor')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::call(static function (): void {
    app(EventOccurrenceReconciliationService::class)->reconcileAllTenants();
})
    ->name('events:occurrences:reconcile')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::job(new ProcessTicketOutboxJob())->everyMinute();
Schedule::job(new ExpireIssuedTicketUnitsJob())->everyMinute();
