<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use App\Support\Schema\ModuleFieldTypes;
use Illuminate\Support\Str;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class ModuleItem extends Model
{
    use UsesTenantConnection;

    protected $fillable = [
        'module_id',
        'account_id',
        'user_id',
        'data',
        'title',
        'slug',
        'relations'
    ];

    protected $casts = [
        'data' => 'array',
        'relations' => 'array'
    ];

    protected $attributes = [
        'data' => [],
        'relations' => []
    ];

    protected $appends = ['title', 'related_models'];

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class);
    }

    /**
     * Retorna o título do item baseado no primeiro campo do tipo "text"
     * ou no campo com nome "title"
     */
    public function getTitleAttribute()
    {
        if (isset($this->data['title'])) {
            return $this->data['title'];
        }

        // Procura o primeiro campo de texto
        if (is_array($this->data)) {
            foreach ($this->data as $key => $value) {
                if (is_string($value) && !empty($value)) {
                    return $value;
                }
            }
        }

        return 'Item #' . $this->_id;
    }

    /**
     * Carrega os modelos relacionados definidos no campo 'relations'
     */
    public function getRelatedModelsAttribute()
    {
        if (empty($this->relations)) {
            return [];
        }

        // Cache dos modelos carregados
        if (isset($this->attributes['_related_models_cache'])) {
            return $this->attributes['_related_models_cache'];
        }

        $result = [];

        // Carrega o esquema de campos do módulo
        $module = $this->module;
        if (!$module) {
            return [];
        }

        $fieldsSchema = $module->fields_schema;
        $fieldTypes = new ModuleFieldTypes();

        // Percorre o esquema para encontrar campos de relacionamento
        foreach ($fieldsSchema as $fieldName => $fieldConfig) {
            if (!isset($fieldConfig['type']) || !$fieldTypes->isRelationType($fieldConfig['type'])) {
                continue;
            }

            // Verifica se temos IDs relacionados para este campo
            if (!isset($this->relations[$fieldName])) {
                $result[$fieldName] = [];
                continue;
            }

            $modelClass = $fieldConfig['model'] ?? null;
            if (!$modelClass || !class_exists($modelClass)) {
                $result[$fieldName] = [];
                continue;
            }

            // Carrega um único modelo ou múltiplos dependendo do tipo
            $isMultiple = $fieldConfig['type'] === 'relations';
            $relatedIds = $this->relations[$fieldName];

            if ($isMultiple) {
                // Carrega múltiplos modelos
                $relatedModels = $modelClass::whereIn('_id', (array)$relatedIds)->get();
                $result[$fieldName] = $relatedModels;
            } else {
                // Carrega um único modelo
                $relatedModel = $modelClass::find($relatedIds);
                $result[$fieldName] = $relatedModel;
            }
        }

        // Armazena em cache para evitar consultas repetidas
        $this->attributes['_related_models_cache'] = $result;

        return $result;
    }

    /**
     * Gera automaticamente o slug baseado no título ao salvar
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->slug) && !empty($model->title)) {
                $model->slug = Str::slug($model->title);
            }
        });

        static::updating(function ($model) {
            if ($model->isDirty('data') && !empty($model->title)) {
                $model->slug = Str::slug($model->title);
            }
        });

        // Processa os relacionamentos antes de salvar
        static::saving(function ($model) {
            $model->processRelations();
        });
    }

    /**
     * Extrai os IDs de relacionamento dos dados e os armazena no campo 'relations'
     */
    protected function processRelations(): void
    {
        $module = $this->module;
        if (!$module) {
            return;
        }

        $fieldsSchema = $module->fields_schema;
        $fieldTypes = new ModuleFieldTypes();
        $relations = $this->relations ?? [];

        // Percorre o esquema para encontrar campos de relacionamento
        foreach ($fieldsSchema as $fieldName => $fieldConfig) {
            if (!isset($fieldConfig['type']) || !$fieldTypes->isRelationType($fieldConfig['type'])) {
                continue;
            }

            // Extrai os IDs de relacionamento dos dados
            if (isset($this->data[$fieldName])) {
                $relations[$fieldName] = $this->data[$fieldName];

                // Remove o campo do array de dados principal
                unset($this->data[$fieldName]);
            }
        }

        $this->relations = $relations;
    }

    /**
     * Acessa um modelo relacionado diretamente como uma propriedade
     */
    public function __get($key)
    {
        $value = parent::__get($key);

        // Se o valor já foi encontrado, retorna
        if ($value !== null) {
            return $value;
        }

        // Verifica se é um relacionamento
        $relatedModels = $this->related_models;
        if (isset($relatedModels[$key])) {
            return $relatedModels[$key];
        }

        return null;
    }

    /**
     * Limpa o cache de modelos relacionados quando um atributo é modificado
     */
    public function setAttribute($key, $value)
    {
        // Se estamos modificando dados ou relações, limpa o cache
        if ($key === 'data' || $key === 'relations') {
            unset($this->attributes['_related_models_cache']);
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * Serializa o modelo para JSON incluindo os relacionamentos carregados
     */
    public function toArray()
    {
        $array = parent::toArray();

        // Adiciona os modelos relacionados se necessário
        if ($this->relationLoaded('module')) {
            // Carrega os relacionamentos automaticamente
            $relatedModels = $this->related_models;

            // Para cada relacionamento, adiciona uma versão simplificada ao array
            foreach ($relatedModels as $relationName => $relationValue) {
                if (is_object($relationValue) && method_exists($relationValue, 'toArray')) {
                    // Se for um único modelo
                    $array['_related'][$relationName] = $relationValue->toArray();
                } elseif ($relationValue instanceof \Illuminate\Support\Collection) {
                    // Se for uma coleção de modelos
                    $array['_related'][$relationName] = $relationValue->map(function ($model) {
                        return $model->toArray();
                    })->toArray();
                }
            }
        }

        return $array;
    }
}
