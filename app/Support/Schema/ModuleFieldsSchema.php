<?php

declare(strict_types=1);

namespace App\Support\Schema;

class ModuleFieldsSchema
{
    public function getDefaultSchema(): array
    {
        return [
            'title' => [
                'type' => 'text',
                'required' => true,
                'label' => 'Título'
            ]
        ];
    }

    /**
     * Retorna a estrutura de um campo de relacionamento
     */
    public function getRelationFieldSchema(string $modelClass, string $label, bool $multiple = false): array
    {
        return [
            'type' => $multiple ? 'relations' : 'relation',
            'required' => false,
            'label' => $label,
            'model' => $modelClass,
            'display_field' => 'name', // Campo padrão para exibição
            'searchable' => true
        ];
    }

    /**
     * Exemplo de como criar um esquema com relacionamentos
     */
    public function getExampleWithRelations(): array
    {
        return [
            'title' => [
                'type' => 'text',
                'required' => true,
                'label' => 'Título'
            ],
            'category' => $this->getRelationFieldSchema(
                'App\\Models\\Tenants\\Category',
                'Categoria'
            ),
            'tags' => $this->getRelationFieldSchema(
                'App\\Models\\Tenants\\Tag',
                'Tags',
                true // múltiplos
            )
        ];
    }
}
