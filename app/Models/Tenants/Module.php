<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\HasMany;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Module extends Model
{
    use UsesTenantConnection, HasSlug;

    protected $connection = 'tenants';

    protected $fillable = [
        'name',
        'description',
        'created_by_type', // 'tenant' ou 'account'
        'created_by_id',
        'settings',
        'permissions_schema'
    ];

    protected $casts = [
        'settings' => 'array',
        'permissions_schema' => 'array'
    ];

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ModuleItem::class);
    }

    public function getDefaultPermissionsSchema(): array
    {
        return [
            'items' => [
                'view' => [
                    'label' => 'Visualizar',
                    'scopes' => [
                        'all' => 'Visualizar todos os itens',
                        'account' => 'Visualizar itens da conta',
                        'owned' => 'Visualizar apenas próprios itens'
                    ]
                ],
                'create' => [
                    'label' => 'Criar',
                    'scopes' => [
                        'all' => 'Criar em qualquer conta',
                        'account' => 'Criar na própria conta'
                    ]
                ],
                'edit' => [
                    'label' => 'Editar',
                    'scopes' => [
                        'all' => 'Editar todos os itens',
                        'account' => 'Editar itens da conta',
                        'owned' => 'Editar apenas próprios itens'
                    ]
                ],
                'delete' => [
                    'label' => 'Deletar',
                    'scopes' => [
                        'all' => 'Deletar todos os itens',
                        'account' => 'Deletar itens da conta',
                        'owned' => 'Deletar apenas próprios itens'
                    ]
                ]
            ],
            'module' => [
                'manage' => [
                    'label' => 'Gerenciar Módulo',
                    'scopes' => [
                        'full' => 'Gerenciamento completo',
                        'settings' => 'Apenas configurações'
                    ]
                ]
            ]
        ];
    }
}
