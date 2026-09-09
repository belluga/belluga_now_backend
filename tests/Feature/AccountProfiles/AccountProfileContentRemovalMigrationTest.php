<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Models\Landlord\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

final class AccountProfileContentRemovalMigrationTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private const RETIRED_INDEX = 'idx_account_profile_types_capability_has_content_v1';

    public function test_migration_removes_only_account_legacy_fields_and_is_idempotent(): void
    {
        $this->refreshLandlordAndTenantDatabases();
        $this->makeMigrationTenantCurrent();
        $database = DB::connection('tenant')->getDatabase();

        $profiles = $database->selectCollection('account_profiles');
        $profileTypes = $database->selectCollection('account_profile_types');
        $events = $database->selectCollection('events');
        $staticAssets = $database->selectCollection('static_assets');

        $profiles->deleteMany([]);
        $profileTypes->deleteMany([]);
        $events->deleteMany([]);
        $staticAssets->deleteMany([]);

        $profiles->insertOne([
            '_id' => 'profile-content-removal',
            'display_name' => 'Profile',
            'bio' => '<p>Canonical bio</p>',
            'content' => '<p>Retired account content</p>',
        ]);
        $profileTypes->insertOne([
            '_id' => 'profile-type-content-removal',
            'type' => 'venue',
            'capabilities' => [
                'has_bio' => true,
                'has_content' => true,
                'has_events' => true,
            ],
        ]);
        $events->insertOne([
            '_id' => 'event-content-preserved',
            'content' => '<p>Event content</p>',
        ]);
        $staticAssets->insertOne([
            '_id' => 'static-content-preserved',
            'content' => '<p>Static asset content</p>',
        ]);

        $profileTypes->createIndex(
            ['capabilities.has_content' => 1],
            ['name' => self::RETIRED_INDEX],
        );
        $profileTypes->createIndex(
            ['capabilities.has_bio' => 1],
            ['name' => 'idx_profile_type_has_bio_preserved'],
        );

        $migration = $this->migration();
        $migration->up();
        $migration->up();

        $profile = $this->arrayFrom($profiles->findOne(['_id' => 'profile-content-removal']));
        $profileType = $this->arrayFrom($profileTypes->findOne(['_id' => 'profile-type-content-removal']));
        $capabilities = $this->arrayFrom($profileType['capabilities'] ?? []);

        self::assertArrayNotHasKey('content', $profile);
        self::assertSame('<p>Canonical bio</p>', $profile['bio'] ?? null);
        self::assertArrayNotHasKey('has_content', $capabilities);
        self::assertTrue($capabilities['has_bio'] ?? false);
        self::assertTrue($capabilities['has_events'] ?? false);
        self::assertNotContains(self::RETIRED_INDEX, $this->indexNames($profileTypes));
        self::assertContains('idx_profile_type_has_bio_preserved', $this->indexNames($profileTypes));
        self::assertSame(
            '<p>Event content</p>',
            $this->arrayFrom($events->findOne(['_id' => 'event-content-preserved']))['content'] ?? null,
        );
        self::assertSame(
            '<p>Static asset content</p>',
            $this->arrayFrom($staticAssets->findOne(['_id' => 'static-content-preserved']))['content'] ?? null,
        );
    }

    public function test_profile_type_cleanup_runs_when_account_profiles_collection_is_absent(): void
    {
        $this->refreshLandlordAndTenantDatabases();
        $this->makeMigrationTenantCurrent();
        $database = DB::connection('tenant')->getDatabase();
        $database->dropCollection('account_profiles');
        $profileTypes = $database->selectCollection('account_profile_types');
        $profileTypes->deleteMany([]);
        $profileTypes->insertOne([
            '_id' => 'profile-type-without-profiles-collection',
            'capabilities' => ['has_content' => false, 'has_bio' => true],
        ]);
        $profileTypes->createIndex(
            ['capabilities.has_content' => 1],
            ['name' => self::RETIRED_INDEX],
        );

        $this->migration()->up();

        $profileType = $this->arrayFrom($profileTypes->findOne([
            '_id' => 'profile-type-without-profiles-collection',
        ]));
        $capabilities = $this->arrayFrom($profileType['capabilities'] ?? []);
        self::assertArrayNotHasKey('has_content', $capabilities);
        self::assertTrue($capabilities['has_bio'] ?? false);
        self::assertNotContains(self::RETIRED_INDEX, $this->indexNames($profileTypes));
    }

    public function test_final_reconciliation_removes_legacy_writes_arriving_during_cutover(): void
    {
        $this->refreshLandlordAndTenantDatabases();
        $this->makeMigrationTenantCurrent();
        $database = DB::connection('tenant')->getDatabase();
        $profiles = $database->selectCollection('account_profiles');
        $profileTypes = $database->selectCollection('account_profile_types');
        $probe = $database->selectCollection('account_profile_content_cutover_probe');
        $profiles->deleteMany([]);
        $profileTypes->deleteMany([]);
        $probe->deleteMany([]);

        $migration = $this->migration();
        $migration->up();

        for ($batch = 0; $batch < 3; $batch++) {
            $goId = 'go-'.$batch;
            $writers = [];
            for ($write = 0; $write < 10; $write++) {
                $suffix = $batch.'-'.$write;
                $writer = new Process([
                    PHP_BINARY,
                    '-r',
                    $this->legacyWriterProcessCode(),
                    $suffix,
                    $goId,
                ], base_path());
                $writer->start();
                $writers[] = $writer;
            }

            $deadline = microtime(true) + 15;
            while ($probe->countDocuments(['batch' => $batch, 'ready' => true]) < 10) {
                self::assertLessThan($deadline, microtime(true), 'Concurrent writers did not become ready.');
                usleep(10_000);
            }
            $probe->insertOne(['_id' => $goId, 'batch' => $batch]);
            $migration->up();

            foreach ($writers as $writer) {
                $writer->wait();
                self::assertTrue($writer->isSuccessful(), $writer->getErrorOutput());
            }

            self::assertGreaterThan(0, $profiles->countDocuments([
                'content' => ['$exists' => true],
            ]));
            $migration->up();
        }

        self::assertSame(0, $profiles->countDocuments([
            'content' => ['$exists' => true],
        ]));
        self::assertSame(0, $profileTypes->countDocuments([
            'capabilities.has_content' => ['$exists' => true],
        ]));
        self::assertSame(30, $profiles->countDocuments([
            'bio' => ['$exists' => true],
        ]));
        self::assertSame(30, $profileTypes->countDocuments([
            'capabilities.has_bio' => true,
        ]));
    }

    private function legacyWriterProcessCode(): string
    {
        return <<<'PHP'
require '/var/www/vendor/autoload.php';
$app = require '/var/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$database = Illuminate\Support\Facades\DB::connection('tenant')->getDatabase();
$probe = $database->selectCollection('account_profile_content_cutover_probe');
$suffix = $argv[1];
$goId = $argv[2];
[$batch] = explode('-', $suffix, 2);
$probe->insertOne(['_id' => 'ready-'.$suffix, 'batch' => (int) $batch, 'ready' => true]);
$deadline = microtime(true) + 15;
while ($probe->countDocuments(['_id' => $goId]) === 0) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, 'Timed out waiting for migration probe release.');
        exit(2);
    }
    usleep(10000);
}
for ($attempt = 0; $attempt < 20; $attempt++) {
    $database->selectCollection('account_profiles')->updateOne(
        ['_id' => 'late-profile-'.$suffix],
        ['$set' => [
            'bio' => '<p>Canonical bio '.$suffix.'</p>',
            'content' => '<p>Legacy writer '.$suffix.'</p>',
        ]],
        ['upsert' => true],
    );
    $database->selectCollection('account_profile_types')->updateOne(
        ['_id' => 'late-profile-type-'.$suffix],
        ['$set' => [
            'capabilities.has_bio' => true,
            'capabilities.has_content' => true,
        ]],
        ['upsert' => true],
    );
    usleep(2000);
}
PHP;
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require base_path(
            'database/migrations/tenants/2026_09_08_000100_remove_account_profile_content_field.php'
        );

        return $migration;
    }

    private function makeMigrationTenantCurrent(): void
    {
        $tenant = new Tenant;
        $tenant->setRawAttributes([
            '_id' => 'tenant-test',
            'slug' => 'tenant-test',
            'database' => (string) config('database.connections.tenant.database'),
        ], true);
        $tenant->exists = true;
        $tenant->makeCurrent();
    }

    /** @return array<int, string> */
    private function indexNames(mixed $collection): array
    {
        return collect(iterator_to_array($collection->listIndexes()))
            ->map(static fn ($index): string => $index->getName())
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function arrayFrom(mixed $value): array
    {
        if (is_object($value) && method_exists($value, 'getArrayCopy')) {
            return $value->getArrayCopy();
        }

        return is_array($value) ? $value : [];
    }
}
