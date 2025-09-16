<?php

namespace App\Traits;

use App\Casts\BrandingDataCast;
use App\DataObjects\Branding\BrandingData;
use Illuminate\Database\Eloquent\Casts\Attribute;

trait HaveBranding
{
    protected string $fieldName = 'branding_data';

    protected function brandingData(): Attribute
    {
        return Attribute::make(
        /**
         * GET: Executado quando você lê $model->brandingData
         */
            get: function ($value) {
                if (is_null($value)) {
                    return null;
                }

                // O $value pode ser uma string JSON (de dados antigos) ou um array (do driver do Mongo)
                $data = is_string($value) ? json_decode($value, true) : (array) $value;

                // Cria o DTO a partir do dado snake_case completo.
                return BrandingData::from($data);
            },
            /**
             * SET: Executado quando você salva $model->brandingData = $dto
             */
            set: function (BrandingData $value) {
                // CORREÇÃO: Retorne o array diretamente.
                // O driver do MongoDB irá convertê-lo para um objeto BSON nativo.
                return $value->toArray();
            }
        );
    }

    /**
     * Override this in your model if you use a different column name.
     */
    protected function brandingColumnName(): string
    {
        return 'branding_data';
    }

    /**
     * Runs on model instantiation; lets us modify $fillable and $casts.
     */
    public function initializeHaveBranding(): void
    {

        if (property_exists($this, 'fillable') && ! in_array($this->fieldName, $this->fillable, true)) {
            $this->fillable[] = $this->fieldName;
        }

        if (property_exists($this, 'casts')) {
            $this->casts = [$this->fieldName => BrandingDataCast::class] + $this->casts;
        } else {
            $this->casts = [$this->fieldName => BrandingDataCast::class];
        }
    }

    /**
     * Accessor/Mutator proxy so you can do $model->branding.
     */
    public function branding(): Attribute
    {
        $column = $this->brandingColumnName();

        return Attribute::make(
            get: fn () => $this->getAttribute($column),
            set: fn ($value) => [$column => $value],
        );
    }

    /**
     * Helpers
     */
    public function getBranding(): mixed
    {
        return $this->getAttribute($this->brandingColumnName());
    }

    public function setBranding(mixed $value): static
    {
        $this->setAttribute($this->brandingColumnName(), $value);
        return $this;
    }

    /**
     * Merge-and-save helper for partial updates.
     */
    public function updateBranding(array $data): bool
    {
        $current = (array) ($this->getBranding() ?? []);
        $merged = array_replace_recursive($current, $data);

        $this->setBranding($merged);
        return $this->save();
    }

    public function hasBranding(): bool
    {
        $value = $this->getBranding();
        return ! empty($value);
    }
}
