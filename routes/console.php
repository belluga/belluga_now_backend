<?php

use App\Application\AccountProfiles\AccountProfileRegistrySeeder;
use App\Application\Security\ApiAbuseSignalRecorder;
use App\Jobs\Ticketing\ExpireIssuedTicketUnitsJob;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\TenantProfileType;
use Belluga\Events\Application\Events\EventOccurrenceReconciliationService;
use Belluga\Events\Application\Operations\EventAsyncOperationsMonitorService;
use Belluga\Events\Contracts\TenantExecutionContextContract;
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

    $registry = (new AccountProfileRegistrySeeder)->defaults();

    TenantProfileType::query()->delete();
    foreach ($registry as $entry) {
        TenantProfileType::create($entry);
    }

    $this->info("Profile type registry updated for tenant [{$tenantSlug}].");

    return 0;
})->purpose('Overwrite tenant profile_type_registry with V1 defaults (personal/artist/venue only).');

Artisan::command('api-security:abuse-signals:prune', function () {
    $result = app(ApiAbuseSignalRecorder::class)->pruneExpired();
    $this->info(sprintf(
        'Pruned abuse signals: raw=%d aggregate=%d',
        (int) ($result['raw_deleted'] ?? 0),
        (int) ($result['aggregate_deleted'] ?? 0)
    ));

    return 0;
})->purpose('Prune expired API abuse signal raw and aggregate records.');

Artisan::command('api-security:abuse-signals:report {--hours=24}', function () {
    $hours = (int) $this->option('hours');
    $summary = app(ApiAbuseSignalRecorder::class)->summarize($hours, []);

    $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return 0;
})->purpose('Print API abuse signal aggregate report for observe-mode/enforcement review.');

// Use class-string scheduling to avoid eager class instantiation during console bootstrap.
Schedule::job(PublishScheduledEventsJob::class)->hourly();

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

Schedule::job(new ProcessTicketOutboxJob)->everyMinute();
Schedule::call(static function (): void {
    app(TenantExecutionContextContract::class)->runForEachTenant(static function (): void {
        app()->call([new ExpireIssuedTicketUnitsJob, 'handle']);
    });
})
    ->name('ticketing:issued-expiry:sweep')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('api-security:abuse-signals:prune')
    ->name('api-security:abuse-signals:prune')
    ->daily()
    ->withoutOverlapping();
