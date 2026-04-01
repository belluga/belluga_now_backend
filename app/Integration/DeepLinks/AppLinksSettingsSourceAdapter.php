<?php

declare(strict_types=1);

namespace App\Integration\DeepLinks;

use App\Models\Tenants\TenantSettings;
use Belluga\DeepLinks\Contracts\AppLinksSettingsSourceContract;
use Belluga\Settings\Models\Landlord\LandlordSettings;
use Illuminate\Support\Arr;

class AppLinksSettingsSourceAdapter implements AppLinksSettingsSourceContract
{
    /**
     * @return array<string, mixed>
     */
    public function currentAppLinksSettings(): array
    {
        $tenantSettings = TenantSettings::current();
        if ($tenantSettings !== null) {
            return $this->normalizeArray($tenantSettings->getAttribute('app_links'));
        }

        $landlordSettings = LandlordSettings::current();
        if ($landlordSettings !== null) {
            return $this->normalizeArray($landlordSettings->getAttribute('app_links'));
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeArray(mixed $value): array
    {
        if (is_array($value)) {
            return Arr::undot($value);
        }
        if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
            /** @var array<string, mixed> $copy */
            $copy = $value->getArrayCopy();

            return Arr::undot($copy);
        }
        if ($value instanceof \Traversable) {
            /** @var array<string, mixed> $copy */
            $copy = iterator_to_array($value);

            return Arr::undot($copy);
        }
        if (is_object($value)) {
            /** @var array<string, mixed> $copy */
            $copy = (array) $value;

            return Arr::undot($copy);
        }

        return [];
    }
}
