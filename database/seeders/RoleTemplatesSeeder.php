<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Landlord\RoleTemplate;
use Illuminate\Database\Seeder;

class RoleTemplatesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $templates = [
            [
                'name' => 'Administrador',
                'description' => 'Acesso completo a todos os módulos',
                'type' => 'tenant',
                'permissions_schema' => [
                    'modules.*' => [
                        'items' => [
                            'view' => ['scope' => 'all'],
                            'create' => ['scope' => 'all'],
                            'edit' => ['scope' => 'all'],
                            'delete' => ['scope' => 'all']
                        ],
                        'module' => [
                            'manage' => ['scope' => 'full']
                        ]
                    ]
                ]
            ],
            [
                'name' => 'Gerente de Conta',
                'description' => 'Acesso completo aos itens da própria conta',
                'type' => 'account',
                'permissions_schema' => [
                    'modules.*' => [
                        'items' => [
                            'view' => ['scope' => 'account'],
                            'create' => ['scope' => 'account'],
                            'edit' => ['scope' => 'account'],
                            'delete' => ['scope' => 'account']
                        ],
                        'module' => [
                            'manage' => ['scope' => 'settings']
                        ]
                    ]
                ]
            ],
            [
                'name' => 'Editor',
                'description' => 'Pode criar e editar, mas só pode excluir próprios itens',
                'type' => 'account',
                'permissions_schema' => [
                    'modules.*' => [
                        'items' => [
                            'view' => ['scope' => 'account'],
                            'create' => ['scope' => 'account'],
                            'edit' => ['scope' => 'account'],
                            'delete' => ['scope' => 'owned']
                        ]
                    ]
                ]
            ],
            [
                'name' => 'Visualizador',
                'description' => 'Pode apenas visualizar itens',
                'type' => 'account',
                'permissions_schema' => [
                    'modules.*' => [
                        'items' => [
                            'view' => ['scope' => 'account']
                        ]
                    ]
                ]
            ],
            [
                'name' => 'Estudante',
                'description' => 'Papel de estudante para LMS',
                'type' => 'account',
                'permissions_schema' => [
                    'courses' => [
                        'items' => [
                            'view' => ['scope' => 'account']
                        ]
                    ],
                    'assignments' => [
                        'items' => [
                            'view' => ['scope' => 'owned'],
                            'submit' => ['scope' => 'owned']
                        ]
                    ]
                ]
            ]
        ];

        foreach ($templates as $template) {
            RoleTemplate::create($template);
        }
    }
}
