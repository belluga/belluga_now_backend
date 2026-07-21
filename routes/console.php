<?php

use App\Application\AccountProfiles\AccountProfileRegistrySeeder;
use App\Application\AccountProfiles\AccountProfileRegistrySyncIndexPrecondition;
use App\Application\AccountProfiles\AccountProfileNestedPublicMembersProjectionBackfillService;
use App\Application\AccountProfiles\AccountProfileOutboxDispatcher;
use App\Application\Accounts\AccountMissingProfileRepairService;
use App\Application\DiscoveryFilters\DiscoveryFilterMapUiBackfillService;
use App\Application\Environment\TenantEnvironmentSnapshotService;
use App\Application\LandlordUsers\LandlordPasswordCredentialBackfillService;
use App\Application\LandlordUsers\LandlordUserAccessService;
use App\Application\Profiles\LandlordProfileService;
use App\Application\Push\PushTopicMembershipService;
use App\Application\Security\ApiAbuseSignalRecorder;
use App\Application\Social\InviteablePeopleProjectionService;
use App\Application\Taxonomies\TaxonomySnapshotBackfillService;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Belluga\Events\Application\Events\EventOccurrenceReconciliationService;
use Belluga\Events\Application\Events\LegacyEventPartiesCanonicalizationService;
use Belluga\Events\Application\Operations\EventAsyncOperationsMonitorService;
use Belluga\Events\Contracts\TenantExecutionContextContract;
use Belluga\Events\Jobs\PublishScheduledEventsJob;
use Belluga\Invites\Application\Feed\InviteExpiryService;
use Belluga\MapPois\Jobs\CleanupOrphanedMapPoisJob;
use Belluga\MapPois\Jobs\RefreshExpiredEventMapPoisJob;
use Belluga\PushHandler\Models\Tenants\PushDevice;
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
    try {
        app(AccountProfileRegistrySyncIndexPrecondition::class)->assertCompatibleTypeIndex();
        app(AccountProfileRegistrySeeder::class)->ensureDefaults();
        $this->info("Profile type registry additively repaired for tenant [{$tenantSlug}].");

        return 0;
    } catch (\Throwable $exception) {
        $this->error($exception->getMessage());

        return 1;
    } finally {
        Tenant::forgetCurrent();
    }
})->purpose('Additive repair for the tenant profile_type_registry V1 defaults without overwriting tenant-owned entries.');

Artisan::command('accounts:missing-profiles:repair {tenant_slug} {--execute} {--confirm=} {--chunk=100}', function () {
    if (! app()->environment(['local', 'testing'])) {
        $this->error('accounts:missing-profiles:repair is local-only by default.');

        return 1;
    }

    $tenantSlug = trim((string) $this->argument('tenant_slug'));
    if ($tenantSlug === '') {
        $this->error('Provide an explicit tenant_slug.');

        return 1;
    }

    $execute = (bool) $this->option('execute');
    $expectedConfirmation = "repair-missing-profiles:{$tenantSlug}";
    if ($execute && trim((string) $this->option('confirm')) !== $expectedConfirmation) {
        $this->error("Execute mode requires --confirm={$expectedConfirmation}.");

        return 1;
    }

    $tenant = Tenant::query()->where('slug', $tenantSlug)->first();
    if (! $tenant instanceof Tenant) {
        $this->error("Tenant not found for slug [{$tenantSlug}].");

        return 1;
    }

    $chunkSize = max(1, min((int) $this->option('chunk'), 500));
    $tenant->makeCurrent();

    try {
        $summary = app(AccountMissingProfileRepairService::class)->run($execute, $chunkSize);
        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    } finally {
        $tenant->forgetCurrent();
    }

    return 0;
})->purpose('Dry-run or repair local tenant accounts that have no active account profile.');

Artisan::command('accounts:test-seeds:purge {tenant_slug} {--execute} {--confirm=} {--chunk=100}', function () {
    if (! app()->environment(['local', 'testing'])) {
        $this->error('accounts:test-seeds:purge is local-only by default.');

        return 1;
    }

    $tenantSlug = trim((string) $this->argument('tenant_slug'));
    if ($tenantSlug === '') {
        $this->error('Provide an explicit tenant_slug.');

        return 1;
    }

    $execute = (bool) $this->option('execute');
    $expectedConfirmation = "purge-test-seeds:{$tenantSlug}";
    if ($execute && trim((string) $this->option('confirm')) !== $expectedConfirmation) {
        $this->error("Execute mode requires --confirm={$expectedConfirmation}.");

        return 1;
    }

    $tenant = Tenant::query()->where('slug', $tenantSlug)->first();
    if (! $tenant instanceof Tenant) {
        $this->error("Tenant not found for slug [{$tenantSlug}].");

        return 1;
    }

    $chunkSize = max(1, min((int) $this->option('chunk'), 500));
    $tenant->makeCurrent();

    try {
        $summary = app(AccountMissingProfileRepairService::class)
            ->purgeTrashedTestSeedAggregates($execute, $chunkSize);
        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    } finally {
        $tenant->forgetCurrent();
    }

    return 0;
})->purpose('Dry-run or purge local tenant soft-deleted test-seed account aggregates.');

Artisan::command('tenant:environment-snapshot:repair {tenant_slug?} {--all} {--reason=manual_repair}', function () {
    /** @var TenantEnvironmentSnapshotService $service */
    $service = app(TenantEnvironmentSnapshotService::class);
    $reason = trim((string) $this->option('reason'));
    if ($reason === '') {
        $reason = 'manual_repair';
    }

    if ($this->option('all')) {
        $count = 0;

        foreach (Tenant::query()->get() as $tenant) {
            if (! $tenant instanceof Tenant) {
                continue;
            }

            $tenant->makeCurrent();

            try {
                $snapshot = $service->repair($tenant, $reason, [
                    'trigger' => 'console',
                    'all' => true,
                ]);
                $count++;

                $this->line(json_encode([
                    'tenant_slug' => (string) $tenant->slug,
                    ...$service->summarize($snapshot),
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } finally {
                $tenant->forgetCurrent();
            }
        }

        $this->info(sprintf('Rebuilt tenant environment snapshots: %d', $count));

        return 0;
    }

    $tenantSlug = trim((string) $this->argument('tenant_slug'));
    if ($tenantSlug === '') {
        $this->error('Provide {tenant_slug} or use --all.');

        return 1;
    }

    $tenant = Tenant::query()->where('slug', $tenantSlug)->first();
    if (! $tenant) {
        $this->error("Tenant not found for slug [{$tenantSlug}].");

        return 1;
    }

    $tenant->makeCurrent();

    try {
        $snapshot = $service->repair($tenant, $reason, [
            'trigger' => 'console',
            'tenant_slug' => $tenantSlug,
        ]);

        $this->line(json_encode([
            'tenant_slug' => $tenantSlug,
            ...$service->summarize($snapshot),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    } finally {
        $tenant->forgetCurrent();
    }

    return 0;
})->purpose('Synchronously rebuild tenant environment snapshots for one tenant or for every tenant.');

Artisan::command('invites:inviteable-people-projection:backfill {tenant_slug?} {--all} {--limit=}', function () {
    /** @var InviteablePeopleProjectionService $service */
    $service = app(InviteablePeopleProjectionService::class);
    $limitValue = $this->option('limit');
    $limit = is_numeric($limitValue) ? max(1, (int) $limitValue) : null;

    if ($this->option('all')) {
        $tenants = Tenant::query()->get();
    } else {
        $tenantSlug = trim((string) $this->argument('tenant_slug'));
        if ($tenantSlug === '') {
            $this->error('Provide {tenant_slug} or use --all.');

            return 1;
        }

        $tenant = Tenant::query()->where('slug', $tenantSlug)->first();
        if (! $tenant instanceof Tenant) {
            $this->error("Tenant not found for slug [{$tenantSlug}].");

            return 1;
        }
        $tenants = collect([$tenant]);
    }

    foreach ($tenants as $tenant) {
        if (! $tenant instanceof Tenant) {
            continue;
        }

        $tenant->makeCurrent();

        try {
            $summary = $service->backfillAllUsers($limit);
            $this->line(json_encode([
                'tenant_slug' => (string) $tenant->slug,
                ...$summary,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } finally {
            $tenant->forgetCurrent();
        }
    }

    return 0;
})->purpose('Backfill inviteable_people_projection for one tenant or every tenant before projection-only reads.');

Artisan::command('account-profiles:nested-public-members-projection:backfill {tenant_slug?} {--all}', function () {
    $runCurrentTenant = function (?string $tenantSlug = null): array {
        $summary = app(AccountProfileNestedPublicMembersProjectionBackfillService::class)
            ->rebuildCurrentTenant();
        if ($tenantSlug !== null) {
            $summary['tenant_slug'] = $tenantSlug;
        }

        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $summary;
    };

    if ($this->option('all')) {
        $count = 0;
        foreach (Tenant::query()->get() as $tenant) {
            if (! $tenant instanceof Tenant) {
                continue;
            }

            $tenant->makeCurrent();
            try {
                $runCurrentTenant((string) $tenant->slug);
                $count++;
            } finally {
                $tenant->forgetCurrent();
            }
        }

        $this->info(sprintf('Processed nested public members projection rebuild for tenants: %d', $count));

        return 0;
    }

    $tenantSlug = trim((string) $this->argument('tenant_slug'));
    if ($tenantSlug !== '') {
        $tenant = Tenant::query()->where('slug', $tenantSlug)->first();
        if (! $tenant) {
            $this->error("Tenant not found for slug [{$tenantSlug}].");

            return 1;
        }

        $tenant->makeCurrent();
        try {
            $runCurrentTenant($tenantSlug);
        } finally {
            $tenant->forgetCurrent();
        }

        return 0;
    }

    if (! Tenant::current()) {
        $this->error('No current tenant. Provide {tenant_slug} or use --all.');

        return 1;
    }

    $runCurrentTenant((string) Tenant::current()?->slug);

    return 0;
})->purpose('Rebuild nested public member projection rows for one tenant or all tenants.');

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

Artisan::command('events:legacy-event-parties:repair {--dry-run}', function () {
    $service = app(LegacyEventPartiesCanonicalizationService::class);
    $summary = $this->option('dry-run')
        ? $service->inspect()
        : $service->repair();

    $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return 0;
})->purpose('Inspect or repair legacy events that still rely on artists/venue event_parties drift.');

Artisan::command('landlord:password-credentials:repair {--dry-run}', function () {
    $summary = app(LandlordPasswordCredentialBackfillService::class)
        ->repair((bool) $this->option('dry-run'));

    $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    return 0;
})->purpose('Inspect or repair landlord users so password credentials are canonical and legacy landlord password fields are removed.');

Artisan::command('landlord:password:set {email}', function (
    LandlordProfileService $profiles,
    LandlordUserAccessService $accessService,
) {
    $email = strtolower(trim((string) $this->argument('email')));

    if ($email === '') {
        $this->error('Provide a non-empty landlord email.');

        return 1;
    }

    $user = LandlordUser::query()
        ->where('emails', 'all', [$email])
        ->first();

    if (! $user instanceof LandlordUser) {
        $this->error("Landlord user not found for email [{$email}].");

        return 1;
    }

    $password = (string) $this->secret('Landlord password');
    if ($password === '') {
        $this->error('Provide a non-empty password.');

        return 1;
    }

    $profiles->updatePassword($user, $password);
    $revoked = $accessService->revokeTokens($user);
    $freshUser = $user->fresh();

    $this->line(json_encode([
        'user_id' => (string) ($freshUser?->getKey() ?? $user->getKey()),
        'emails' => $freshUser?->emails ?? $user->emails ?? [],
        'revoked_tokens' => $revoked,
        'password_credentials' => collect($freshUser?->credentials ?? [])
            ->where('provider', 'password')
            ->pluck('subject')
            ->values()
            ->all(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    return 0;
})->purpose('Set the canonical landlord password for a landlord email and revoke existing sessions.');

Artisan::command('events:occurrences:repair {tenant_slug?} {--all}', function () {
    /** @var EventOccurrenceReconciliationService $service */
    $service = app(EventOccurrenceReconciliationService::class);

    if ($this->option('all')) {
        $service->reconcileAllTenants();
        $this->info('Reconciled event occurrences for all tenants (manual repair mode).');

        return 0;
    }

    $tenantSlug = trim((string) $this->argument('tenant_slug'));
    if ($tenantSlug !== '') {
        $tenant = Tenant::query()->where('slug', $tenantSlug)->first();
        if (! $tenant) {
            $this->error("Tenant not found for slug [{$tenantSlug}].");

            return 1;
        }

        $tenant->makeCurrent();
        try {
            $service->reconcileCurrentTenant();
            $this->info(sprintf(
                'Reconciled event occurrences for tenant [%s] (manual repair mode).',
                $tenantSlug
            ));
        } finally {
            $tenant->forgetCurrent();
        }

        return 0;
    }

    if (! Tenant::current()) {
        $this->error('No current tenant. Provide {tenant_slug} or use --all.');

        return 1;
    }

    $service->reconcileCurrentTenant();
    $this->info(sprintf(
        'Reconciled event occurrences for tenant [%s] (manual repair mode).',
        (string) Tenant::current()?->slug
    ));

    return 0;
})->purpose('Explicit manual repair for event occurrence projections; not part of recurring scheduler runtime.');

Artisan::command('events:diagnostic:append-profile-group-member {tenant_ref} {event_id} {profile_id} {--with-event-party}', function () {
    if (! app()->environment(['local', 'testing'])) {
        $this->error('events:diagnostic:append-profile-group-member is local/testing only.');

        return 1;
    }

    $tenantRef = trim((string) $this->argument('tenant_ref'));
    $eventId = trim((string) $this->argument('event_id'));
    $profileId = trim((string) $this->argument('profile_id'));

    if ($tenantRef === '' || $eventId === '' || $profileId === '') {
        $this->error('tenant_ref, event_id, and profile_id are required.');

        return 1;
    }

    $tenant = Tenant::query()
        ->where('_id', $tenantRef)
        ->orWhere('slug', $tenantRef)
        ->first();
    if (! $tenant) {
        $this->error("Tenant not found for reference [{$tenantRef}].");

        return 1;
    }

    $normalizeArray = static function ($value): array {
        if ($value instanceof \MongoDB\Model\BSONArray || $value instanceof \MongoDB\Model\BSONDocument) {
            return $value->getArrayCopy();
        }

        return is_array($value) ? $value : [];
    };

    $normalizeDocumentArray = static function ($value) use ($normalizeArray): array {
        return array_values(array_map(static function ($row): array {
            if ($row instanceof \MongoDB\Model\BSONArray || $row instanceof \MongoDB\Model\BSONDocument) {
                $row = $row->getArrayCopy();
            }

            return is_array($row) ? $row : [];
        }, $normalizeArray($value)));
    };

    $normalizeScalarArray = static function ($value) use ($normalizeArray): array {
        return array_values($normalizeArray($value));
    };

    $tenant->makeCurrent();
    try {
        $event = \Belluga\Events\Models\Tenants\Event::query()
            ->where('_id', $eventId)
            ->first();
        if (! $event) {
            $this->error("Event not found for id [{$eventId}].");

            return 1;
        }

        $profile = AccountProfile::query()
            ->where('_id', $profileId)
            ->first();
        if (! $profile instanceof AccountProfile) {
            $this->error("Account profile not found for id [{$profileId}].");

            return 1;
        }

        $groups = $normalizeDocumentArray($event->profile_groups ?? []);
        if (! isset($groups[0])) {
            $this->error('Missing first profile group for diagnostic mutation.');

            return 1;
        }

        $memberIds = $normalizeScalarArray($groups[0]['account_profile_ids'] ?? []);
        if (! in_array($profileId, $memberIds, true)) {
            $memberIds[] = $profileId;
        }
        $groups[0]['account_profile_ids'] = $memberIds;
        $event->profile_groups = $groups;

        if ((bool) $this->option('with-event-party')) {
            $eventParties = $normalizeDocumentArray($event->event_parties ?? []);
            $canonicalParty = [
                'party_type' => trim((string) ($profile->profile_type ?? '')),
                'party_ref_id' => (string) $profile->getKey(),
                'permissions' => [
                    'can_edit' => false,
                ],
                'metadata' => [
                    'display_name' => trim((string) ($profile->display_name ?? '')),
                    'slug' => trim((string) ($profile->slug ?? '')),
                    'profile_type' => trim((string) ($profile->profile_type ?? '')),
                    'avatar_url' => $profile->avatar_url ?? null,
                    'cover_url' => $profile->cover_url ?? null,
                    'taxonomy_terms' => $normalizeDocumentArray($profile->taxonomy_terms ?? []),
                ],
            ];

            if ($canonicalParty['party_type'] === '' || $canonicalParty['metadata']['display_name'] === '') {
                $this->error('Canonical event-party mutation requires a profile with non-empty profile_type and display_name.');

                return 1;
            }

            $replaced = false;
            foreach ($eventParties as $index => $row) {
                if ((string) ($row['party_ref_id'] ?? '') !== $profileId) {
                    continue;
                }

                $eventParties[$index] = $canonicalParty;
                $replaced = true;
                break;
            }

            if (! $replaced) {
                $eventParties[] = $canonicalParty;
            }

            $event->event_parties = $eventParties;
        }

        $event->save();
        $event = $event->fresh() ?? $event;
        app(EventOccurrenceReconciliationService::class)->reconcileEvent($event);

        $this->line(json_encode([
            'tenant_ref' => $tenantRef,
            'event_id' => $eventId,
            'profile_id' => $profileId,
            'with_event_party' => (bool) $this->option('with-event-party'),
            'occurrence_projections_repaired' => true,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return 0;
    } finally {
        $tenant->forgetCurrent();
    }
})->purpose('Append a profile id to the first event profile group, optionally mirror a canonical event-party payload, and resync occurrence projections for local diagnostics.');

Artisan::command('push:topics:repair {tenant_slug?} {--all} {--chunk=200}', function (PushTopicMembershipService $memberships) {
    $chunkSize = max(1, min((int) $this->option('chunk'), 1000));

    $repairCurrentTenant = function (?string $tenantSlug = null) use ($memberships, $chunkSize): array {
        $processed = 0;
        $reconciled = 0;

        PushDevice::query()
            ->where('is_active', true)
            ->orderBy('account_user_id')
            ->orderBy('_id')
            ->chunk($chunkSize, function ($devices) use ($memberships, &$processed, &$reconciled): void {
                collect($devices)
                    ->filter(static fn ($device): bool => $device instanceof PushDevice)
                    ->groupBy(static fn (PushDevice $device): string => trim((string) ($device->account_user_id ?? '')))
                    ->each(function ($userDevices, string $userId) use ($memberships, &$processed, &$reconciled): void {
                        $processed += $userDevices->count();
                        if ($userId === '') {
                            return;
                        }

                        $tokens = $userDevices
                            ->map(static fn (PushDevice $device): string => trim((string) ($device->push_token ?? '')))
                            ->filter(static fn (string $token): bool => $token !== '')
                            ->unique()
                            ->values()
                            ->all();

                        if ($tokens === []) {
                            return;
                        }

                        $memberships->syncTokensForUser($userId, $tokens);
                        $reconciled += count($tokens);
                    });
            });

        return [
            'tenant_slug' => $tenantSlug ?? (string) Tenant::current()?->slug,
            'processed_active_devices' => $processed,
            'reconciled_tokens' => $reconciled,
        ];
    };

    if ($this->option('all')) {
        $summaries = [];

        foreach (Tenant::query()->get() as $tenant) {
            if (! $tenant instanceof Tenant) {
                continue;
            }

            $tenant->makeCurrent();

            try {
                $summaries[] = $repairCurrentTenant((string) $tenant->slug);
            } finally {
                $tenant->forgetCurrent();
            }
        }

        $this->line(json_encode([
            'mode' => 'all',
            'tenants' => $summaries,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return 0;
    }

    $tenantSlug = trim((string) $this->argument('tenant_slug'));
    if ($tenantSlug !== '') {
        $tenant = Tenant::query()->where('slug', $tenantSlug)->first();
        if (! $tenant instanceof Tenant) {
            $this->error("Tenant not found for slug [{$tenantSlug}].");

            return 1;
        }

        $tenant->makeCurrent();

        try {
            $this->line(json_encode(
                $repairCurrentTenant($tenantSlug),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));
        } finally {
            $tenant->forgetCurrent();
        }

        return 0;
    }

    if (! Tenant::current()) {
        $this->error('No current tenant. Provide {tenant_slug} or use --all.');

        return 1;
    }

    $this->line(json_encode(
        $repairCurrentTenant(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ));

    return 0;
})->purpose('Manually reproject canonical push topic memberships for active device tokens in one tenant or all tenants.');

Artisan::command('taxonomies:term-snapshots:repair {tenant_slug?} {--all} {--type=} {--value=}', function () {
    $taxonomyType = trim((string) $this->option('type'));
    $termValue = trim((string) $this->option('value'));
    $taxonomyType = $taxonomyType === '' ? null : $taxonomyType;
    $termValue = $termValue === '' ? null : $termValue;
    $hasFailures = static fn (array $summary): bool => (int) data_get($summary, 'totals.failed', 0) > 0;

    $runCurrentTenant = function (?string $tenantSlug = null) use ($taxonomyType, $termValue): array {
        $summary = app(TaxonomySnapshotBackfillService::class)->repair($taxonomyType, $termValue);
        if ($tenantSlug !== null) {
            $summary['tenant_slug'] = $tenantSlug;
        }

        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $summary;
    };

    if ($this->option('all')) {
        $count = 0;
        $failedTenants = [];
        foreach (Tenant::query()->get() as $tenant) {
            if (! $tenant instanceof Tenant) {
                continue;
            }

            $tenant->makeCurrent();
            try {
                $summary = $runCurrentTenant((string) $tenant->slug);
                if ($hasFailures($summary)) {
                    $failedTenants[] = (string) $tenant->slug;
                }
                $count++;
            } finally {
                $tenant->forgetCurrent();
            }
        }

        $this->info(sprintf('Repaired taxonomy term snapshots for tenants: %d', $count));
        if ($failedTenants !== []) {
            $this->error(sprintf(
                'Taxonomy term snapshot repair failed for tenant(s): %s',
                implode(', ', $failedTenants)
            ));

            return 1;
        }

        return 0;
    }

    $tenantSlug = trim((string) $this->argument('tenant_slug'));
    if ($tenantSlug !== '') {
        $tenant = Tenant::query()->where('slug', $tenantSlug)->first();
        if (! $tenant) {
            $this->error("Tenant not found for slug [{$tenantSlug}].");

            return 1;
        }

        $tenant->makeCurrent();
        try {
            $summary = $runCurrentTenant($tenantSlug);
        } finally {
            $tenant->forgetCurrent();
        }

        return $hasFailures($summary) ? 1 : 0;
    }

    if (! Tenant::current()) {
        $this->error('No current tenant. Provide {tenant_slug} or use --all.');

        return 1;
    }

    $summary = $runCurrentTenant((string) Tenant::current()?->slug);

    return $hasFailures($summary) ? 1 : 0;
})->purpose('Repair denormalized taxonomy term display snapshots for tenant read models.');

Artisan::command('discovery-filters:backfill-map-ui {tenant_slug?} {--all} {--force}', function () {
    $runCurrentTenant = function (?string $tenantSlug = null): array {
        $summary = app(DiscoveryFilterMapUiBackfillService::class)
            ->backfillCurrentTenant(force: (bool) $this->option('force'));
        if ($tenantSlug !== null) {
            $summary['tenant_slug'] = $tenantSlug;
        }

        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $summary;
    };

    if ($this->option('all')) {
        $count = 0;
        foreach (Tenant::query()->get() as $tenant) {
            if (! $tenant instanceof Tenant) {
                continue;
            }

            $tenant->makeCurrent();
            try {
                $runCurrentTenant((string) $tenant->slug);
                $count++;
            } finally {
                $tenant->forgetCurrent();
            }
        }

        $this->info(sprintf('Processed discovery filter map-ui backfill for tenants: %d', $count));

        return 0;
    }

    $tenantSlug = trim((string) $this->argument('tenant_slug'));
    if ($tenantSlug !== '') {
        $tenant = Tenant::query()->where('slug', $tenantSlug)->first();
        if (! $tenant) {
            $this->error("Tenant not found for slug [{$tenantSlug}].");

            return 1;
        }

        $tenant->makeCurrent();
        try {
            $runCurrentTenant($tenantSlug);
        } finally {
            $tenant->forgetCurrent();
        }

        return 0;
    }

    if (! Tenant::current()) {
        $this->error('No current tenant. Provide {tenant_slug} or use --all.');

        return 1;
    }

    $runCurrentTenant((string) Tenant::current()?->slug);

    return 0;
})->purpose('Backfill legacy map_ui.filters into canonical discovery_filters public_map.primary.');

Schedule::call(static function (): void {
    app(TenantExecutionContextContract::class)->runForEachTenant(static function (): void {
        PublishScheduledEventsJob::dispatch();
    });
})
    ->name('events:publication:publish_scheduled')
    ->hourly()
    ->withoutOverlapping();

Schedule::call(static function (): void {
    app(EventAsyncOperationsMonitorService::class)->evaluate();
})
    ->name('events:async:monitor')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::call(static function (): void {
    app(TenantExecutionContextContract::class)->runForEachTenant(static function (): void {
        CleanupOrphanedMapPoisJob::dispatch(['account_profile', 'static'], 60);
    });
})
    ->name('map_pois:cleanup_orphaned')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::call(static function (): void {
    app(TenantExecutionContextContract::class)->runForEachTenant(static function (): void {
        RefreshExpiredEventMapPoisJob::dispatch();
    });
})
    ->name('events:map_pois:refresh_expired')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::call(static function (): void {
    app(TenantExecutionContextContract::class)->runForEachTenant(static function (): void {
        app(InviteExpiryService::class)->expireStaleTargetsForCurrentTenant();
    });
})
    ->name('invites:expire_finished_occurrences')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::command('api-security:abuse-signals:prune')
    ->name('api-security:abuse-signals:prune')
    ->daily()
    ->withoutOverlapping();
