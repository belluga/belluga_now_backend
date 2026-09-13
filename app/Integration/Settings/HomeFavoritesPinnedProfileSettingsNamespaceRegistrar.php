<?php

declare(strict_types=1);

namespace App\Integration\Settings;

use App\Application\AccountProfiles\HomeFavoritesPinnedProfileService;
use Belluga\Settings\Contracts\SettingsRegistryContract;
use Belluga\Settings\Support\SettingsNamespaceDefinition;

final class HomeFavoritesPinnedProfileSettingsNamespaceRegistrar
{
    public function register(SettingsRegistryContract $registry): void
    {
        if ($registry->find(HomeFavoritesPinnedProfileService::NAMESPACE, 'tenant') !== null) {
            return;
        }

        $registry->register(new SettingsNamespaceDefinition(
            namespace: HomeFavoritesPinnedProfileService::NAMESPACE,
            scope: 'tenant',
            label: 'Perfil em destaque',
            groupLabel: 'Branding',
            ability: 'tenant-branding:update',
            fields: [
                'account_profile_id' => [
                    'type' => 'string',
                    'nullable' => true,
                    'label' => 'Perfil',
                    'default' => null,
                    'order' => 10,
                ],
            ],
            order: 15,
            description: 'Perfil tenant-owned exibido no primeiro slot dos favoritos da Home.',
            icon: 'push_pin',
        ));
    }
}
