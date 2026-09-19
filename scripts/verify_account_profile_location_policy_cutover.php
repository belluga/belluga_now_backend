<?php

declare(strict_types=1);

use App\Application\AccountProfiles\AccountProfileTypeIndexManifest;
use App\Application\AccountProfiles\Capabilities\AccountProfileCapabilityRegistry;
use Illuminate\Contracts\Console\Kernel;

final class AccountProfileLocationPolicyCutoverVerifier
{
    private const CAPABILITY_MIGRATION = '2026_09_15_000100_hard_cut_account_profile_capabilities';

    private const LOCATION_MIGRATION = '2026_09_15_000200_hard_cut_account_profile_location_policy';

    /** @var array<string, array<string, int>> */
    private const LOCATION_INDEXES = [
        'idx_account_profile_types_map_poi_location_policy_v1' => [
            'capabilities.is_map_poi_enabled.value' => 1,
            'capabilities.location_policy.value' => 1,
            'type' => 1,
        ],
        'idx_account_profile_types_physical_host_location_policy_v1' => [
            'capabilities.is_physical_host_enabled.value' => 1,
            'capabilities.location_policy.value' => 1,
            'type' => 1,
        ],
        'idx_account_profile_types_reference_location_policy_v1' => [
            'capabilities.is_reference_location_enabled.value' => 1,
            'capabilities.location_policy.value' => 1,
            'type' => 1,
        ],
    ];

    /** @var list<string> */
    private array $failures = [];

    public function __construct(private readonly string $root) {}

    public function run(AccountProfileCapabilityRegistry $registry): int
    {
        $this->verifyDefinitions($registry->definitions());
        $this->verifyIndexManifest((new AccountProfileTypeIndexManifest)->definitions());
        $this->verifyMigrationSource();
        $this->verifyIntegrityLock();

        if ($this->failures === []) {
            fwrite(STDOUT, "[ACCOUNT-PROFILE-LOCATION-CUTOVER] PASS\n");

            return 0;
        }

        fwrite(
            STDERR,
            "[ACCOUNT-PROFILE-LOCATION-CUTOVER] FAIL\n - ".implode("\n - ", array_unique($this->failures))."\n",
        );

        return 1;
    }

    /** @param array<string, array<string, mixed>> $definitions */
    private function verifyDefinitions(array $definitions): void
    {
        $expected = [
            'location_policy' => [
                'domain' => 'location',
                'value_type' => 'enum',
                'default_value' => 'disabled',
                'fail_closed_value' => 'disabled',
                'allowed_values' => ['disabled', 'optional', 'required'],
            ],
            'is_map_poi_enabled' => $this->booleanLocationDefinition(),
            'is_physical_host_enabled' => $this->booleanLocationDefinition(),
            'is_reference_location_enabled' => $this->booleanLocationDefinition(),
        ];

        foreach ($expected as $key => $fields) {
            $actual = $definitions[$key] ?? null;
            if (! is_array($actual)) {
                $this->fail("missing canonical capability definition [{$key}]");

                continue;
            }
            foreach ($fields as $field => $value) {
                if (($actual[$field] ?? null) !== $value) {
                    $this->fail("capability [{$key}] has non-canonical [{$field}]");
                }
            }
            if (($actual['parameters'] ?? null) !== [] || ($actual['resources'] ?? null) !== []) {
                $this->fail("location capability [{$key}] must not invent parameters or resources");
            }
        }

        if (array_key_exists('is_poi_enabled', $definitions)) {
            $this->fail('retired capability [is_poi_enabled] remains in the runtime registry');
        }
    }

    /** @return array{domain:string,value_type:string,default_value:false,fail_closed_value:false} */
    private function booleanLocationDefinition(): array
    {
        return [
            'domain' => 'location',
            'value_type' => 'boolean',
            'default_value' => false,
            'fail_closed_value' => false,
        ];
    }

    /** @param list<array<string, mixed>> $definitions */
    private function verifyIndexManifest(array $definitions): void
    {
        $byName = [];
        foreach ($definitions as $definition) {
            $name = $definition['name'] ?? null;
            if (is_string($name)) {
                $byName[$name] = $definition;
            }
        }

        foreach (self::LOCATION_INDEXES as $name => $keys) {
            $actual = $byName[$name] ?? null;
            if (! is_array($actual) || ($actual['keys'] ?? null) !== $keys) {
                $this->fail("index manifest does not expose exact location index [{$name}]");
            }
        }
    }

    private function verifyMigrationSource(): void
    {
        $path = $this->migrationPath(self::LOCATION_MIGRATION);
        $source = @file_get_contents($path);
        if (! is_string($source)) {
            $this->fail('location cutover migration source is missing');

            return;
        }

        foreach ([
            "'capabilities.location_policy'",
            "'capabilities.is_map_poi_enabled'",
            "'capabilities.is_physical_host_enabled'",
            "'capabilities.is_reference_location_enabled'",
            "'capabilities.is_poi_enabled' => ''",
            'private const BATCH_SIZE = 100',
            'bulkWrite(',
            "'host_admission_fence_revision' => \$hostFenceRevision",
            "'location.type' => 1",
            "'place_ref.id' => 1",
            "'programming_items.place_ref.id' => 1",
        ] as $literal) {
            if (! str_contains($source, $literal)) {
                $this->fail("location migration is missing required literal [{$literal}]");
            }
        }

        foreach (array_keys(self::LOCATION_INDEXES) as $name) {
            if (! str_contains($source, "'{$name}'")) {
                $this->fail("location migration is missing index [{$name}]");
            }
        }

        foreach (['use App\\', 'use Belluga\\', 'app(', 'resolve(', 'config(', 'env('] as $forbidden) {
            if (str_contains($source, $forbidden)) {
                $this->fail("historical location migration uses forbidden runtime authority [{$forbidden}]");
            }
        }
    }

    private function verifyIntegrityLock(): void
    {
        $lockPath = $this->root.'/database/migration-integrity-lock.json';
        try {
            $lock = json_decode(
                (string) file_get_contents($lockPath),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (Throwable $error) {
            $this->fail('migration integrity lock cannot be decoded: '.$error->getMessage());

            return;
        }

        $rows = [];
        foreach (($lock['migrations'] ?? []) as $row) {
            if (is_array($row) && ($row['scope'] ?? null) === 'tenant') {
                $rows[(string) ($row['basename'] ?? '')] = $row;
            }
        }

        foreach ([self::CAPABILITY_MIGRATION, self::LOCATION_MIGRATION] as $basename) {
            $row = $rows[$basename] ?? null;
            $path = $this->migrationPath($basename);
            if (! is_array($row)) {
                $this->fail("migration integrity lock is missing [{$basename}]");

                continue;
            }
            $hash = is_file($path) ? hash_file('sha256', $path) : false;
            if (! is_string($hash) || ($row['target_sha256'] ?? null) !== $hash) {
                $this->fail("migration integrity hash does not match [{$basename}]");
            }
        }
    }

    private function migrationPath(string $basename): string
    {
        return $this->root.'/database/migrations/tenants/'.$basename.'.php';
    }

    private function fail(string $message): void
    {
        $this->failures[] = $message;
    }
}

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$verifier = new AccountProfileLocationPolicyCutoverVerifier($root);
exit($verifier->run($app->make(AccountProfileCapabilityRegistry::class)));
