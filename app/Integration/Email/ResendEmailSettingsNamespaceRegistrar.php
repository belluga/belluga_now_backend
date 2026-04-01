<?php

declare(strict_types=1);

namespace App\Integration\Email;

use Belluga\Settings\Contracts\SettingsRegistryContract;
use Belluga\Settings\Support\SettingsNamespaceDefinition;

class ResendEmailSettingsNamespaceRegistrar
{
    public function register(SettingsRegistryContract $registry): void
    {
        if ($registry->find('resend_email', 'tenant') !== null) {
            return;
        }

        $registry->register(new SettingsNamespaceDefinition(
            namespace: 'resend_email',
            scope: 'tenant',
            label: 'Resend',
            groupLabel: 'Communications',
            ability: 'push-settings:update',
            fields: [
                'token' => [
                    'type' => 'string',
                    'nullable' => true,
                    'label' => 'API Token',
                    'order' => 10,
                    'default' => null,
                ],
                'from' => [
                    'type' => 'string',
                    'nullable' => true,
                    'label' => 'From',
                    'order' => 20,
                    'default' => null,
                ],
                'to' => [
                    'type' => 'array',
                    'nullable' => false,
                    'label' => 'To',
                    'order' => 30,
                    'default' => [],
                ],
                'cc' => [
                    'type' => 'array',
                    'nullable' => false,
                    'label' => 'Cc',
                    'order' => 40,
                    'default' => [],
                ],
                'bcc' => [
                    'type' => 'array',
                    'nullable' => false,
                    'label' => 'Bcc',
                    'order' => 50,
                    'default' => [],
                ],
                'reply_to' => [
                    'type' => 'array',
                    'nullable' => false,
                    'label' => 'Reply-To',
                    'order' => 60,
                    'default' => [],
                ],
            ],
            order: 120,
            labelI18nKey: 'settings.resend_email.namespace.label',
            description: 'Tenant-owned Resend delivery defaults for transactional web email flows.',
            descriptionI18nKey: 'settings.resend_email.namespace.description',
            icon: 'mail',
        ));
    }
}
