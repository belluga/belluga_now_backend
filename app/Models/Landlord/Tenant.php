<?php

namespace App\Models\Landlord;

use App\Models\LandlordUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use MongoDB\Laravel\Eloquent\DocumentModel;
use MongoDB\Laravel\Relations\BelongsToMany;
use MongoDB\Laravel\Relations\EmbedsMany;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;
use Spatie\Multitenancy\Models\Tenant as BaseTenant;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Tenant extends BaseTenant
{
    use UsesLandlordConnection, HasSlug, DocumentModel;

    protected $fillable = [
        'name',
        'subdomain'
    ];

//    protected $connection = 'landlord';

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(LandlordUser::class);
    }

    public function domains(): EmbedsMany {
        return $this->embedsMany(Domain::class);
    }

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug');
    }

    public static function booted(): void
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

    protected function createDatabase(): void
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

    protected function runMigrations(): void
    {
        Artisan::call('migrate', [
            '--database' => config('multitenancy.tenant_database_connection_name'),
            '--path' => 'database/migrations/tenants',
            '--force' => true
        ]);

    }
}
