<?php

declare(strict_types=1);

namespace App\Integration\DeepLinks;

use Belluga\Settings\Contracts\SettingsRegistryContract;
use Belluga\Settings\Support\SettingsNamespaceDefinition;

class AppLinksSettingsNamespaceRegistrar
{
    public function register(SettingsRegistryContract $registry): void
    {
        if ($registry->find('app_links', 'tenant') !== null) {
            return;
        }

        $registry->register(new SettingsNamespaceDefinition(
            namespace: 'app_links',
            scope: 'tenant',
            label: 'App Links',
            groupLabel: 'Mobile',
            ability: 'push-settings:update',
            fields: [
                'android.sha256_cert_fingerprints' => [
                    'type' => 'array',
                    'nullable' => false,
                    'label' => 'Android SHA-256 Fingerprints',
                    'label_i18n_key' => 'settings.app_links.android.sha256_cert_fingerprints.label',
                    'default' => [],
                    'order' => 10,
                ],
                'android.store_url' => [
                    'type' => 'string',
                    'nullable' => true,
                    'label' => 'Android Store URL',
                    'label_i18n_key' => 'settings.app_links.android.store_url.label',
                    'default' => null,
                    'order' => 15,
                ],
                'ios.team_id' => [
                    'type' => 'string',
                    'nullable' => true,
                    'label' => 'Apple Team ID',
                    'label_i18n_key' => 'settings.app_links.ios.team_id.label',
                    'default' => null,
                    'order' => 20,
                ],
                'ios.paths' => [
                    'type' => 'array',
                    'nullable' => false,
                    'label' => 'iOS Universal Link Paths',
                    'label_i18n_key' => 'settings.app_links.ios.paths.label',
                    'default' => ['/invite*', '/convites*'],
                    'order' => 30,
                ],
                'ios.store_url' => [
                    'type' => 'string',
                    'nullable' => true,
                    'label' => 'iOS Store URL',
                    'label_i18n_key' => 'settings.app_links.ios.store_url.label',
                    'default' => null,
                    'order' => 35,
                ],
            ],
            order: 40,
            labelI18nKey: 'settings.app_links.namespace.label',
            description: 'Per-tenant Android App Links and iOS Universal Links credentials + store targets.',
            descriptionI18nKey: 'settings.app_links.namespace.description',
            icon: 'link',
        ));
    }
}
