<?php

declare(strict_types=1);

namespace App\Integration\Push;

use Belluga\Settings\Contracts\SettingsRegistryContract;
use Belluga\Settings\Support\SettingsNamespaceDefinition;

class PushSettingsNamespaceRegistrar
{
    public function register(SettingsRegistryContract $registry): void
    {
        $ability = 'push-settings:update';

        $registry->register(new SettingsNamespaceDefinition(
            namespace: 'push',
            scope: 'tenant',
            label: 'Push',
            groupLabel: 'Notifications',
            ability: $ability,
            fields: [
                'enabled' => [
                    'type' => 'boolean',
                    'nullable' => false,
                    'label' => 'Enabled',
                    'label_i18n_key' => 'settings.push.enabled.label',
                    'order' => 10,
                ],
                'throttles' => [
                    'type' => 'object',
                    'nullable' => true,
                    'label' => 'Throttles',
                    'label_i18n_key' => 'settings.push.throttles.label',
                    'order' => 20,
                ],
                'max_ttl_days' => [
                    'type' => 'integer',
                    'nullable' => false,
                    'label' => 'Max TTL Days',
                    'label_i18n_key' => 'settings.push.max_ttl_days.label',
                    'default' => 7,
                    'enabled_if' => [
                        'groups' => [
                            [
                                'rules' => [
                                    ['field_id' => 'push.enabled', 'operator' => 'equals', 'value' => true],
                                ],
                            ],
                        ],
                    ],
                    'order' => 30,
                ],
                'message_routes' => [
                    'type' => 'array',
                    'nullable' => false,
                    'label' => 'Message Routes',
                    'label_i18n_key' => 'settings.push.message_routes.label',
                    'default' => [],
                    'order' => 40,
                ],
                'message_types' => [
                    'type' => 'array',
                    'nullable' => false,
                    'label' => 'Message Types',
                    'label_i18n_key' => 'settings.push.message_types.label',
                    'default' => [],
                    'order' => 50,
                ],
            ],
            order: 100,
            labelI18nKey: 'settings.push.namespace.label',
            description: 'Push delivery and throttling defaults.',
            descriptionI18nKey: 'settings.push.namespace.description',
            icon: 'notifications',
        ));

        $registry->register(new SettingsNamespaceDefinition(
            namespace: 'firebase',
            scope: 'tenant',
            label: 'Firebase',
            groupLabel: 'Notifications',
            ability: $ability,
            fields: [
                'apiKey' => [
                    'type' => 'string',
                    'nullable' => false,
                    'label' => 'API Key',
                    'label_i18n_key' => 'settings.firebase.api_key.label',
                    'order' => 10,
                ],
                'appId' => [
                    'type' => 'string',
                    'nullable' => false,
                    'label' => 'App ID',
                    'label_i18n_key' => 'settings.firebase.app_id.label',
                    'order' => 20,
                ],
                'projectId' => [
                    'type' => 'string',
                    'nullable' => false,
                    'label' => 'Project ID',
                    'label_i18n_key' => 'settings.firebase.project_id.label',
                    'order' => 30,
                ],
                'messagingSenderId' => [
                    'type' => 'string',
                    'nullable' => false,
                    'label' => 'Messaging Sender ID',
                    'label_i18n_key' => 'settings.firebase.messaging_sender_id.label',
                    'order' => 40,
                ],
                'storageBucket' => [
                    'type' => 'string',
                    'nullable' => false,
                    'label' => 'Storage Bucket',
                    'label_i18n_key' => 'settings.firebase.storage_bucket.label',
                    'order' => 50,
                ],
            ],
            order: 110,
            labelI18nKey: 'settings.firebase.namespace.label',
            description: 'Firebase client settings used by FCM-enabled push flows.',
            descriptionI18nKey: 'settings.firebase.namespace.description',
            icon: 'cloud',
        ));
    }
}
