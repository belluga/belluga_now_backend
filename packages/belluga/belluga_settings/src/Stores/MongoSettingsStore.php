<?php

declare(strict_types=1);

namespace Belluga\Settings\Stores;

use Belluga\Settings\Contracts\SettingsMergePolicyContract;
use Belluga\Settings\Contracts\SettingsStoreContract;
use Belluga\Settings\Models\Landlord\LandlordSettings;
use Belluga\Settings\Models\SettingsDocument;
use Belluga\Settings\Models\Tenants\TenantSettings;
use Belluga\Settings\Support\BsonNormalizer;
use Belluga\Settings\Support\SettingsNamespaceDefinition;
use RuntimeException;

class MongoSettingsStore implements SettingsStoreContract
{
    public function __construct(private readonly SettingsMergePolicyContract $mergePolicy) {}

    public function getNamespaceValue(string $scope, string $namespace): array
    {
        $modelClass = $this->modelClassForScope($scope);
        $settings = $modelClass::current();

        if (! $settings) {
            return [];
        }

        return BsonNormalizer::toArray($settings->getAttribute($namespace));
    }

    public function mergeNamespace(
        string $scope,
        string $namespace,
        array $changes,
        SettingsNamespaceDefinition $definition
    ): array {
        $modelClass = $this->modelClassForScope($scope);
        /** @var SettingsDocument|null $settings */
        $settings = $modelClass::current();

        if (! $settings) {
            /** @var SettingsDocument $settings */
            $settings = new $modelClass;
            $settings->setAttribute('_id', SettingsDocument::ROOT_ID);
        }

        $current = BsonNormalizer::toArray($settings->getAttribute($namespace));
        $merged = $this->mergePolicy->merge($current, $changes);

        $settings->setAttribute($namespace, $merged);
        $settings->save();

        return BsonNormalizer::toArray($settings->getAttribute($namespace));
    }

    /**
     * @return class-string<SettingsDocument>
     */
    private function modelClassForScope(string $scope): string
    {
        return match ($scope) {
            'tenant' => TenantSettings::class,
            'landlord' => LandlordSettings::class,
            default => throw new RuntimeException("Unsupported settings scope [{$scope}]."),
        };
    }
}
