<?php

declare(strict_types=1);

namespace App\Integration\Settings;

use Belluga\Settings\Contracts\SettingsRegistryContract;
use Belluga\Settings\Support\SettingsNamespaceDefinition;

class TenantPublicAuthMethodSettingsNamespaceRegistrar
{
    public function register(SettingsRegistryContract $registry): void
    {
        if ($registry->find('tenant_public_auth', 'landlord') === null) {
            $registry->register(new SettingsNamespaceDefinition(
                namespace: 'tenant_public_auth',
                scope: 'landlord',
                label: 'Tenant Public Auth',
                groupLabel: 'Identity',
                ability: 'tenant-public-auth-settings:update',
                fields: [
                    'available_methods' => [
                        'type' => 'array',
                        'nullable' => false,
                        'label' => 'Available Methods',
                        'label_i18n_key' => 'settings.tenant_public_auth.available_methods.label',
                        'options' => ['password', 'phone_otp'],
                        'default' => ['password', 'phone_otp'],
                        'order' => 10,
                    ],
                    'allow_tenant_customization' => [
                        'type' => 'boolean',
                        'nullable' => false,
                        'label' => 'Allow Tenant Customization',
                        'label_i18n_key' => 'settings.tenant_public_auth.allow_tenant_customization.label',
                        'default' => true,
                        'order' => 20,
                    ],
                ],
                order: 25,
                labelI18nKey: 'settings.tenant_public_auth.namespace.label',
                description: 'Landlord-owned tenant-public auth availability and customization governance.',
                descriptionI18nKey: 'settings.tenant_public_auth.namespace.description',
                icon: 'key',
            ));
        }

        if ($registry->find('tenant_public_auth', 'tenant') === null) {
            $registry->register(new SettingsNamespaceDefinition(
                namespace: 'tenant_public_auth',
                scope: 'tenant',
                label: 'Tenant Public Auth',
                groupLabel: 'Identity',
                ability: 'tenant-public-auth-settings:update',
                fields: [
                    'enabled_methods' => [
                        'type' => 'array',
                        'nullable' => false,
                        'label' => 'Enabled Methods',
                        'label_i18n_key' => 'settings.tenant_public_auth.enabled_methods.label',
                        'options' => ['password', 'phone_otp'],
                        'default' => [],
                        'order' => 10,
                    ],
                ],
                order: 25,
                labelI18nKey: 'settings.tenant_public_auth.namespace.label',
                description: 'Tenant-owned enabled auth methods when landlord customization is allowed.',
                descriptionI18nKey: 'settings.tenant_public_auth.namespace.description',
                icon: 'key',
            ));
        }
    }
}
