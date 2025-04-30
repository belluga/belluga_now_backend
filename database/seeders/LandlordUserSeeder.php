<?php

namespace Database\Seeders;

use App\Models\Landlord\LandlordUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class LandlordUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Criar o usuário administrador principal do sistema
        LandlordUser::create([
            'name' => 'Administrador do Sistema',
            'email' => 'admin@system.com',
            'password' => Hash::make('password'),
            'is_admin' => true,
            'is_active' => true,
        ]);

        // Criar um segundo administrador para testes
        LandlordUser::create([
            'name' => 'Gerente de Contas',
            'email' => 'manager@system.com',
            'password' => Hash::make('password'),
            'is_admin' => true,
            'is_active' => true,
        ]);
    }
}
