<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Module;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
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
}
