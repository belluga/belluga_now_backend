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
        'permissions_schema',
        'fields_schema',
        'show_in_menu',
        'menu_position',
        'menu_icon'
    ];

    protected $casts = [
        'settings' => 'array',
        'permissions_schema' => 'array',
        'fields_schema' => 'array',
        'show_in_menu' => 'boolean'
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

    public function getDefaultFieldsSchema(): array
    {
        return [
            'fields' => [
                [
                    'name' => 'title',
                    'type' => 'text',
                    'label' => 'Título',
                    'required' => true
                ],
                [
                    'name' => 'content',
                    'type' => 'rich_text',
                    'label' => 'Conteúdo',
                    'required' => false
                ]
            ]
        ];
    }

    public function getSupportedFieldTypes(): array
    {
        return [
            'text' => 'Texto Simples',
            'textarea' => 'Texto Longo',
            'rich_text' => 'Editor Rico',
            'number' => 'Número',
            'date' => 'Data',
            'datetime' => 'Data e Hora',
            'boolean' => 'Sim/Não',
            'select' => 'Seleção Única',
            'multiselect' => 'Seleção Múltipla',
            'file' => 'Arquivo',
            'image' => 'Imagem',
            'repeater' => 'Campos Repetíveis'
        ];
    }

    protected static function boot()
    {
        parent::boot();

        // Garante que os campos necessários estejam presentes
        static::creating(function ($model) {
            if (empty($model->fields_schema)) {
                $model->fields_schema = $model->getDefaultFieldsSchema();
            }

            if (empty($model->permissions_schema)) {
                $model->permissions_schema = $model->getDefaultPermissionsSchema();
            }

            if (!isset($model->show_in_menu)) {
                $model->show_in_menu = false;
            }

            if (empty($model->menu_position)) {
                $model->menu_position = 0;
            }

            if (empty($model->menu_icon)) {
                $model->menu_icon = 'document';
            }
        });
    }
}
