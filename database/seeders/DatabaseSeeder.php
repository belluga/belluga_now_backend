<?php

namespace Database\Seeders;

use App\Models\Landlord\Tenant;
use App\Models\Tenants\Category;
use App\Models\LandlordUser;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {

        LandlordUser::factory()->create([
            'name' => 'Super Admin',
            'email' => 'test@example.com',
        ]);

        Category::factory()->count(5)->create();
    }

    public function initialize(): void
    {
        Tenant::factory()->initialize();
    }
}
