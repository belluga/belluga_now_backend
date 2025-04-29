<?php

declare(strict_types=1);

namespace App\Support\Schema;

class ModuleFieldTypes
{
    public function getSupportedTypes(): array
    {
        return [
            'text',
            'number',
            'boolean',
            'date',
            'datetime',
            'select',
            'multiselect',
            'file',
            'image',
            'relation',
            'relations'
        ];
    }

    /**
     * Retorna os tipos de campo que representam relacionamentos com outros modelos
     */
    public function getRelationTypes(): array
    {
        return [
            'relation',    // Relacionamento um-para-um
            'relations'    // Relacionamento um-para-muitos
        ];
    }

    /**
     * Verifica se um tipo de campo é um relacionamento
     */
    public function isRelationType(string $type): bool
    {
        return in_array($type, $this->getRelationTypes());
    }
}
