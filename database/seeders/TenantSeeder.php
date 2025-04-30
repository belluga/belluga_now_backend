<?php

namespace Database\Seeders;

use App\Models\Landlord\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TenantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Criar o tenant da escola
        Tenant::create([
            'name' => 'Escola Digital Educar',
            'description' => 'Sistema de gestão escolar para alunos, professores e disciplinas',
            'subdomain' => 'escola',
            'domain' => 'escoladigital.local',
            'is_active' => true,
            'settings' => [
                'primary_color' => '#3498db',
                'secondary_color' => '#2ecc71',
                'logo' => 'tenants/escola/logo.png',
                'favicon' => 'tenants/escola/favicon.ico',
                'timezone' => 'America/Sao_Paulo',
                'locale' => 'pt_BR',
                'max_users' => 100,
                'features' => [
                    'file_upload' => true,
                    'api_access' => true,
                    'custom_modules' => true,
                    'exports' => true
                ]
            ],
            'database_name' => 'escola_db_' . Str::random(8)
        ]);

        // Criar o tenant para gerenciamento de acordes de guitarra
        Tenant::create([
            'name' => 'Acordes de Guitarra Pro',
            'description' => 'Sistema para gerenciamento de acordes, escalas e partituras para guitarristas',
            'subdomain' => 'acordes',
            'domain' => 'acordesguitarra.local',
            'is_active' => true,
            'settings' => [
                'primary_color' => '#e74c3c',
                'secondary_color' => '#f39c12',
                'logo' => 'tenants/acordes/logo.png',
                'favicon' => 'tenants/acordes/favicon.ico',
                'timezone' => 'America/Sao_Paulo',
                'locale' => 'pt_BR',
                'max_users' => 50,
                'features' => [
                    'file_upload' => true,
                    'api_access' => true,
                    'custom_modules' => true,
                    'exports' => false
                ]
            ],
            'database_name' => 'acordes_db_' . Str::random(8)
        ]);
    }
}
