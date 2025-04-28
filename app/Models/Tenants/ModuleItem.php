<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class ModuleItem extends Model
{
    use UsesTenantConnection;

    protected $connection = 'tenants';

    protected $fillable = [
        'module_id',
        'account_id',
        'user_id',
        'data',
        'title',
        'slug'
    ];

    protected $casts = [
        'data' => 'array'
    ];

    protected $appends = ['title'];

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
     * Gera automaticamente o slug baseado no título ao salvar
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->slug) && !empty($model->title)) {
                $model->slug = \Illuminate\Support\Str::slug($model->title);
            }
        });

        static::updating(function ($model) {
            if ($model->isDirty('data') && !empty($model->title)) {
                $model->slug = \Illuminate\Support\Str::slug($model->title);
            }
        });
    }
}
