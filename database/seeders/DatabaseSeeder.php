<?php

namespace Database\Seeders;

use App\Models\Landlord\Tenant;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Primeiro, criamos o administrador do sistema (landlord)
        $this->call(LandlordUserSeeder::class);

        // Em seguida, criamos os tenants com suas configurações específicas
        $this->call(TenantSeeder::class);

        // Depois, populamos cada tenant com seus dados
        $this->call(GuitarChordsTenantSeeder::class);
    }

    public function initialize(): void
    {
        Tenant::factory()->initialize();
    }
}
