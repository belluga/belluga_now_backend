<?php

declare(strict_types=1);

namespace Tests\Feature\Taxonomies;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Application\Taxonomies\TaxonomySnapshotBackfillService;
use App\Jobs\Taxonomies\RepairTaxonomyTermSnapshotsJob;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\StaticAsset;
use App\Models\Tenants\Taxonomy;
use App\Models\Tenants\TaxonomyTerm;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Belluga\MapPois\Models\Tenants\MapPoi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class TaxonomyTermDisplaySnapshotsTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    private static bool $bootstrapped = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        AccountProfile::query()->delete();
        StaticAsset::query()->delete();
        Event::query()->delete();
        EventOccurrence::query()->delete();
        MapPoi::query()->delete();
        TaxonomyTerm::query()->delete();
        Taxonomy::query()->delete();
    }

    public function test_repair_backfills_legacy_snapshots_across_document_read_models_idempotently(): void
    {
        $taxonomy = $this->createTaxonomyAndTerm();
        $legacyTerms = [['type' => 'style', 'value' => 'samba']];
        $account = Account::query()->create([
            'name' => 'Snapshot Account',
            'document' => (string) Str::uuid(),
        ]);

        $profile = AccountProfile::query()->create([
            'account_id' => (string) $account->_id,
            'profile_type' => 'artist',
            'display_name' => 'Legacy Artist',
            'taxonomy_terms' => $legacyTerms,
            'is_active' => true,
        ]);
        $staticAsset = StaticAsset::query()->create([
            'profile_type' => 'poi',
            'display_name' => 'Legacy Static',
            'taxonomy_terms' => $legacyTerms,
            'is_active' => true,
        ]);
        $event = Event::query()->create([
            'title' => 'Legacy Event',
            'type' => ['slug' => 'show', 'name' => 'Show'],
            'taxonomy_terms' => $legacyTerms,
            'venue' => ['display_name' => 'Legacy Venue', 'taxonomy_terms' => $legacyTerms],
            'event_parties' => [[
                'party_type' => 'artist',
                'party_ref_id' => (string) $profile->_id,
                'permissions' => ['can_edit' => true],
                'metadata' => [
                    'display_name' => 'Legacy Artist',
                    'profile_type' => 'artist',
                    'taxonomy_terms' => $legacyTerms,
                ],
            ]],
            'publication' => ['status' => 'published'],
            'date_time_start' => Carbon::now()->addDay(),
            'date_time_end' => Carbon::now()->addDay()->addHours(2),
        ]);
        $occurrence = EventOccurrence::query()->create([
            'event_id' => (string) $event->_id,
            'occurrence_index' => 0,
            'slug' => 'legacy-event',
            'title' => 'Legacy Event',
            'taxonomy_terms' => $legacyTerms,
            'venue' => ['display_name' => 'Legacy Venue', 'taxonomy_terms' => $legacyTerms],
            'linked_account_profiles' => [[
                'id' => (string) $profile->_id,
                'display_name' => 'Legacy Artist',
                'profile_type' => 'artist',
                'taxonomy_terms' => $legacyTerms,
            ]],
            'artists' => [[
                'id' => (string) $profile->_id,
                'display_name' => 'Legacy Artist',
                'profile_type' => 'artist',
                'taxonomy_terms' => $legacyTerms,
            ]],
            'starts_at' => Carbon::now()->addDay(),
            'ends_at' => Carbon::now()->addDay()->addHours(2),
        ]);
        $poi = MapPoi::query()->create([
            'ref_type' => 'event',
            'ref_id' => (string) $event->_id,
            'ref_slug' => 'legacy-event',
            'ref_path' => '/agenda/evento/legacy-event',
            'name' => 'Legacy Event',
            'category' => 'event',
            'location' => ['type' => 'Point', 'coordinates' => [-40.0, -20.0]],
            'taxonomy_terms' => $legacyTerms,
            'taxonomy_terms_flat' => ['style:samba'],
            'is_active' => true,
            'exact_key' => '-20.00000,-40.00000',
        ]);

        $exitCode = Artisan::call('taxonomies:term-snapshots:repair', [
            '--type' => 'style',
            '--value' => 'samba',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('Samba', data_get($profile->fresh()->taxonomy_terms, '0.name'));
        $this->assertSame('Style', data_get($staticAsset->fresh()->taxonomy_terms, '0.taxonomy_name'));
        $this->assertSame('Samba', data_get($event->fresh()->taxonomy_terms, '0.label'));
        $this->assertSame('Style', data_get($event->fresh()->venue, 'taxonomy_terms.0.taxonomy_name'));
        $this->assertSame('Samba', data_get($event->fresh()->event_parties, '0.metadata.taxonomy_terms.0.name'));
        $this->assertSame('Samba', data_get($occurrence->fresh()->taxonomy_terms, '0.name'));
        $this->assertSame('Style', data_get($occurrence->fresh()->linked_account_profiles, '0.taxonomy_terms.0.taxonomy_name'));
        $this->assertSame('Samba', data_get($occurrence->fresh()->artists, '0.taxonomy_terms.0.label'));
        $this->assertSame('Samba', data_get($poi->fresh()->taxonomy_terms, '0.name'));
        $this->assertSame('style:samba', data_get($poi->fresh()->taxonomy_terms_flat, '0'));

        $summary = $this->app->make(TaxonomySnapshotBackfillService::class)->repair('style', 'samba');
        $this->assertSame(0, (int) data_get($summary, 'totals.failed'));
        $this->assertSame(0, (int) data_get($summary, 'totals.repaired'));
        $this->assertSame((string) $taxonomy->slug, (string) data_get($summary, 'scope.taxonomy_type'));
    }

    public function test_taxonomy_display_name_updates_dispatch_fanout_and_term_slug_update_is_rejected(): void
    {
        [$taxonomy, $term] = $this->createTaxonomyAndTerm(asTuple: true);

        Queue::fake();

        $taxonomyUpdate = $this->patchJson(
            "{$this->base_tenant_api_admin}taxonomies/{$taxonomy->_id}",
            ['name' => 'Style Updated'],
            $this->getHeaders()
        );
        $taxonomyUpdate->assertStatus(200);
        Queue::assertPushed(RepairTaxonomyTermSnapshotsJob::class, function (RepairTaxonomyTermSnapshotsJob $job): bool {
            return $this->readPrivateProperty($job, 'taxonomyType') === 'style'
                && $this->readPrivateProperty($job, 'termValue') === null;
        });

        $termUpdate = $this->patchJson(
            "{$this->base_tenant_api_admin}taxonomies/{$taxonomy->_id}/terms/{$term->_id}",
            ['name' => 'Samba Updated'],
            $this->getHeaders()
        );
        $termUpdate->assertStatus(200);
        Queue::assertPushed(RepairTaxonomyTermSnapshotsJob::class, function (RepairTaxonomyTermSnapshotsJob $job): bool {
            return $this->readPrivateProperty($job, 'taxonomyType') === 'style'
                && $this->readPrivateProperty($job, 'termValue') === 'samba';
        });

        $slugUpdate = $this->patchJson(
            "{$this->base_tenant_api_admin}taxonomies/{$taxonomy->_id}/terms/{$term->_id}",
            ['slug' => 'samba-novo'],
            $this->getHeaders()
        );
        $slugUpdate->assertStatus(422);
    }

    public function test_taxonomy_snapshot_backfill_uses_cursor_iteration_not_full_collection_materialization(): void
    {
        $source = file_get_contents(app_path('Application/Taxonomies/TaxonomySnapshotBackfillService.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('->cursor()', $source);
        $this->assertStringNotContainsString('->get()->each', $source);
    }

    private function createTaxonomyAndTerm(bool $asTuple = false): Taxonomy|array
    {
        $taxonomy = Taxonomy::query()->create([
            'slug' => 'style',
            'name' => 'Style',
            'applies_to' => ['account_profile', 'static_asset', 'event'],
        ]);
        $term = TaxonomyTerm::query()->create([
            'taxonomy_id' => (string) $taxonomy->_id,
            'slug' => 'samba',
            'name' => 'Samba',
        ]);

        return $asTuple ? [$taxonomy, $term] : $taxonomy;
    }

    private function readPrivateProperty(object $object, string $property): mixed
    {
        $reflection = new \ReflectionClass($object);
        $propertyReflection = $reflection->getProperty($property);
        $propertyReflection->setAccessible(true);

        return $propertyReflection->getValue($object);
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Zeta', 'subdomain' => 'tenant-zeta'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-zeta.test']
        );

        $service->initialize($payload);
    }
}
