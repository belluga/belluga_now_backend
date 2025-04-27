<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MongoDB\Client;
use MongoDB\Laravel\Schema\Blueprint;

class TenantsMongoArtisan extends Command
{
    protected $signature = 'mongo-tenants:artisan
                            {artisanCommand : The artisan command to run}
                            {--tenant=* : Specific tenant IDs to target}
                            {--skip-mongo-init : Skip MongoDB database initialization}';

    protected $description = 'Execute an artisan command for each tenant with MongoDB support';

    public function handle()
    {
        $tenants = $this->getTargetTenants();

        foreach ($tenants as $tenant) {
            $this->processTenant($tenant);
        }

        return 0;
    }

    protected $tables = [
        "accounts",
        "categories",
        "transactions",
    ];

    protected function getTargetTenants()
    {
        return $this->option('tenant')
            ? Tenant::whereIn('id', $this->option('tenant'))->get()
            : Tenant::all();
    }

    protected function processTenant(Tenant $tenant)
    {
        $this->info("\nProcessing tenant {$tenant->id} ({$tenant->name})...");

        try {
            // Initialize tenant context
            $tenant->makeCurrent();

            // Initialize MongoDB database if needed
//            if (!$this->option('skip-mongo-init') && config('database.tenant_connection') === 'mongodb') {
                $this->initializeMongoDatabase($tenant);
//            }

            // Execute the Artisan command
//            $this->executeArtisanCommand();

            $this->info("✅ Completed for tenant {$tenant->id}");
        } catch (\Exception $e) {
            $this->error("❌ Failed for tenant {$tenant->id}: " . $e->getMessage());
        } finally {
            $tenant->forgetCurrent();
        }
    }

    protected function initializeMongoDatabase(Tenant $tenant): void
    {
        $this->info("Initializing MongoDB database for tenant...");

        $client = new Client(
            config('database.connections.mongodb.dsn'),
            config('database.connections.mongodb.options', []),
            config('database.connections.mongodb.driver_options', [])
        );

        // Force database creation by inserting a document
        $client->selectDatabase($tenant->database)
            ->selectCollection('_initialization')
            ->insertOne(['initialized_at' => now()]);

        // Create required collections and indexes
        $this->ensureCollectionsExist($client, $tenant);
    }

    protected function ensureCollectionsExist(Client $client, Tenant $tenant): void
    {
        $db = $client->selectDatabase($tenant->database);
        $requiredCollections = ['accounts', 'products', 'orders']; // Your collections here

        foreach ($this->tables as $collection) {
            if (!in_array($collection, iterator_to_array($db->listCollectionNames()))) {
                $db->createCollection($collection);
                $this->info("Created collection: {$collection}");
            }

            // Add collection-specific indexes
            $this->createIndexes($db->selectCollection($collection), $collection);
        }
    }

    protected function createIndexes($collection, $collectionName)
    {
        switch ($collectionName) {
            case 'accounts':
            case 'categories':
                $collection->createIndex(['slug' => 1], ['unique' => true]);
                break;
            case 'transactions':
                $collection->createIndex(['transaction_date' => -1]);
                $collection->createIndex(['amount' => 1]);
                $collection->createIndex(['description' => "text"]);
                $collection->createIndex(['created_at' => -1]);
                $collection->createIndex(['updated_at' => -1]);
        }

//        $collection->createIndex(['slug' => 1], ['unique' => true]);

//        Schema::create($collectionName, function (Blueprint $collection) {
//            $collection->index('slug');
//        });

//        $indexMap = [
//            'users' => [
//                ['email' => 1],
//                ['options' => ['unique' => true]]
//            ],
//            'products' => [
//                ['sku' => 1],
//                ['options' => ['unique' => true]]
//            ]
//        ];

//        if (isset($indexMap[$collectionName])) {
//            $collection->createIndex(...$indexMap[$collectionName]);
//        }
    }

    protected function executeArtisanCommand()
    {
        $command = $this->argument('artisanCommand');

        // Special handling for migrate command
        if (str_starts_with($command, 'migrate') && config('database.tenant_connection') === 'mongodb') {
            $this->info("Skipping migrations for MongoDB tenant");
            return;
        }

        $this->call($command);
    }
}
