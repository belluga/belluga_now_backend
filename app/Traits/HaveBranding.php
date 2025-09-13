<?php

namespace App\Traits;

use App\Casts\BrandingDataCast;
use Illuminate\Database\Eloquent\Casts\Attribute;

trait HaveBranding
{
    protected string $fieldName = 'branding_data';
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
