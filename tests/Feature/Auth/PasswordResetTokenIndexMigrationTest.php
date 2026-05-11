<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Application\Auth\PasswordResetTokenService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class PasswordResetTokenIndexMigrationTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshLandlordAndTenantDatabases();
    }

    public function test_password_reset_token_index_migration_replaces_legacy_unique_user_index(): void
    {
        $collection = DB::connection('landlord')->getMongoDB()->selectCollection('password_reset_tokens');
        $collection->dropIndexes();
        $collection->createIndex(['user_id' => 1], [
            'name' => 'user_id_1',
            'unique' => true,
        ]);
        $collection->createIndex(['token' => 1], ['name' => 'token_1']);
        $collection->insertMany([
            [
                'user_id' => 'legacy-user-1',
                'token' => 'legacy-token-1',
            ],
            [
                'user_id' => 'legacy-user-2',
                'token' => 'legacy-token-2',
            ],
        ]);

        $migration = include base_path('database/migrations/landlord/2026_05_11_000900_update_password_reset_token_indexes.php');
        $migration->up();

        $indexes = collect(iterator_to_array($collection->listIndexes()))
            ->mapWithKeys(static fn (array|object $index): array => [
                (string) data_get($index, 'name') => (array) data_get($index, 'key', []),
            ]);

        $this->assertTrue($indexes->has('user_id_1'));
        $this->assertFalse($indexes->has('token_1'));
        $this->assertSame(['slot_key' => 1], $indexes->get('slot_key_1'));
        $this->assertSame(['token_lookup_hash' => 1], $indexes->get('token_lookup_hash_1'));
        $this->assertSame(0, $collection->countDocuments(['slot_key' => ['$exists' => false]]));

        $service = app(PasswordResetTokenService::class);
        $service->issueForUser('user-1', 'user@example.org', PasswordResetTokenService::TENANT_USERS_BROKER, 'tenant-a');
        $service->issueForUser('user-1', 'user@example.org', PasswordResetTokenService::TENANT_USERS_BROKER, 'tenant-b');

        $this->assertSame(2, $collection->countDocuments(['user_id_string' => 'user-1']));
    }
}
