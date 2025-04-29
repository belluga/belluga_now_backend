<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenants\CreatedByType;
use App\Models\Tenants\Module;

class MakeSampleModule
{
    /**
     * Cria um módulo de exemplo com relacionamentos
     */
    public static function create(): Module
    {
        $module = new Module([
            'name' => 'Produtos',
            'description' => 'Módulo de exemplo para gerenciar produtos',
            'created_by_type' => CreatedByType::TENANT,
            'show_in_menu' => true,
            'menu_position' => 1,
            'menu_icon' => 'shopping-cart'
        ]);

        // Define o esquema de campos incluindo relacionamentos
        $module->fields_schema = [
            'title' => [
                'type' => 'text',
                'required' => true,
                'label' => 'Nome do Produto'
            ],
            'description' => [
                'type' => 'text',
                'required' => false,
                'label' => 'Descrição',
                'multiline' => true
            ],
            'price' => [
                'type' => 'number',
                'required' => true,
                'label' => 'Preço',
                'min' => 0
            ],
            'active' => [
                'type' => 'boolean',
                'required' => false,
                'label' => 'Ativo',
                'default' => true
            ],
            'category' => [
                'type' => 'relation',
                'required' => false,
                'label' => 'Categoria',
                'model' => 'App\\Models\\Tenants\\Category',
                'display_field' => 'name',
                'searchable' => true
            ],
            'tags' => [
                'type' => 'relations',
                'required' => false,
                'label' => 'Tags',
                'model' => 'App\\Models\\Tenants\\Category', // Usando Category como exemplo, seria Tag
                'display_field' => 'name',
                'searchable' => true
            ],
            'image' => [
                'type' => 'image',
                'required' => false,
                'label' => 'Imagem'
            ]
        ];

        $module->save();

        return $module;
    }

    /**
     * Cria um item de exemplo para o módulo
     */
    public static function createSampleItem(Module $module): void
    {
        // Verifica se existe alguma categoria
        $category = \App\Models\Tenants\Category::first();
        $categoryId = $category ? $category->_id : null;

        // Dados iniciais
        $data = [
            'title' => 'Produto de exemplo',
            'description' => 'Este é um produto de exemplo criado automaticamente',
            'price' => 99.99,
            'active' => true,
        ];

        // Relações
        $relations = [];

        if ($categoryId) {
            $relations['category'] = $categoryId;
            $relations['tags'] = [$categoryId]; // Usando a mesma categoria como tag de exemplo
        }

        // Cria o item
        $item = $module->items()->create([
            'data' => $data,
            'relations' => $relations
        ]);

        // Poderia adicionar mais configurações aqui
    }
}
