<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {

        User::factory()->create([
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
