<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class AccountProfileQueryPerformanceGuardrailTest extends TestCaseTenant
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

        Tenant::query()->firstOrFail()->makeCurrent();
    }

    public function test_public_account_profile_agenda_occurrence_lookup_uses_canonical_nested_member_index_support(): void
    {
        $serviceSource = $this->readSource('app/Application/AccountProfiles/AccountProfileAgendaOccurrencesService.php');
        $storeSource = $this->readSource('packages/belluga/belluga_events/src/Application/Events/EventOccurrenceNestedAccountStore.php');

        $this->assertStringContainsString('EventOccurrenceNestedAccountStore', $serviceSource);
        $this->assertStringContainsString('occurrenceIdsForMemberProfiles([$profileId])', $serviceSource);
        $this->assertStringNotContainsString("'event_parties'", $serviceSource);
        $this->assertStringNotContainsString("where('artists.id'", $serviceSource);
        $this->assertStringContainsString("'nested_profile.id' => ['\$in' => \$normalizedProfileIds]", $storeSource);
        $this->assertContains(
            'idx_accounts_nested_member_lookup_v1',
            $this->indexNames('accounts_nested'),
            'Public account-profile agenda lookup must be backed by the canonical accounts_nested member lookup index.'
        );
    }

    /**
     * @return array<int, string>
     */
    private function indexNames(string $collection): array
    {
        $names = [];
        foreach (DB::connection('tenant')->getCollection($collection)->listIndexes() as $index) {
            $names[] = (string) $index->getName();
        }

        return $names;
    }

    private function readSource(string $relativePath): string
    {
        $fullPath = base_path($relativePath);
        $contents = file_get_contents($fullPath);
        $this->assertNotFalse($contents, sprintf('Failed to read [%s].', $fullPath));

        return (string) $contents;
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

        $tenant = Tenant::query()->first();
        if ($tenant) {
            $this->landlord->tenant_primary->slug = $tenant->slug;
            $this->landlord->tenant_primary->subdomain = $tenant->subdomain;
            $this->landlord->tenant_primary->id = (string) $tenant->_id;
            $this->landlord->tenant_primary->role_admin->id = (string) ($tenant->roleTemplates()->first()?->_id ?? '');
        }
    }
}
