<?php

namespace App\Models;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use MongoDB\Laravel\Eloquent\DocumentModel;
use MongoDB\Laravel\Relations\BelongsToMany;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;
use Spatie\Multitenancy\Models\Tenant as BaseTenant;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Tenant extends BaseTenant
{
    use UsesLandlordConnection, HasSlug, DocumentModel;

    protected $fillable = [
        'name',
        'subdomain',
        'database'
    ];

//    protected $connection = 'landlord';

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug');
    }

    public static function booted()
    {
        static::creating(function (Tenant $tenant) {
            if (empty($tenant->slug)) {
                $tenant->generateSlug();
            }

            $tenant->database = 'tenant_' . str_replace('-', '_', $tenant->slug);
        });

        static::created(function (Tenant $tenant) {
            $tenant->createDatabase();
        });
    }

    protected function createDatabase()
    {
        $this->makeCurrent();

        try {
            DB::connection(env('DB_CONNECTION_TENANT', 'mongodb'),);
        } catch (\Exception $e) {
            throw new \Exception("MongoDB connection failed: " . $e->getMessage());
        }

        // Run migrations
        $this->runMigrations();

        $this->forgetCurrent();
    }

    protected function runMigrations()
    {

        // Skip standard migrations for MongoDB
        if (env('DB_CONNECTION_TENANT') === 'mongodb') {
            return;
        }

        Artisan::call('tenants:artisan', [
            'artisanCommand' => 'migrate --database=tenant --force',
            '--tenant' => $this->id
        ]);

//        Artisan::call('tenants:artisan', [
//            '--database' => env('DB_CONNECTION_TENANT', 'mongodb'),
//            '--force' => true,
//            '--tenant' => $this->id
//        ]);
    }
}
