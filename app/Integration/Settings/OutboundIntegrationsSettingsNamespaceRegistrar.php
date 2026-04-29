<?php

declare(strict_types=1);

namespace App\Integration\Settings;

use Belluga\Settings\Contracts\SettingsRegistryContract;
use Belluga\Settings\Support\SettingsNamespaceDefinition;

class OutboundIntegrationsSettingsNamespaceRegistrar
{
    public function register(SettingsRegistryContract $registry): void
    {
        if ($registry->find('outbound_integrations', 'tenant') !== null) {
            return;
        }

        $registry->register(new SettingsNamespaceDefinition(
            namespace: 'outbound_integrations',
            scope: 'tenant',
            label: 'Outbound Integrations',
            groupLabel: 'Communications',
            ability: 'tenant-public-auth-settings:update',
            fields: [
                'whatsapp.webhook_url' => [
                    'type' => 'string',
                    'nullable' => true,
                    'label' => 'WhatsApp Webhook URL',
                    'label_i18n_key' => 'settings.outbound_integrations.whatsapp.webhook_url.label',
                    'default' => null,
                    'order' => 10,
                ],
                'otp.webhook_url' => [
                    'type' => 'string',
                    'nullable' => true,
                    'label' => 'OTP Webhook URL',
                    'label_i18n_key' => 'settings.outbound_integrations.otp.webhook_url.label',
                    'default' => null,
                    'order' => 20,
                ],
                'otp.use_whatsapp_webhook' => [
                    'type' => 'boolean',
                    'nullable' => false,
                    'label' => 'Use WhatsApp Webhook for OTP',
                    'label_i18n_key' => 'settings.outbound_integrations.otp.use_whatsapp_webhook.label',
                    'default' => true,
                    'order' => 30,
                ],
                'otp.delivery_channel' => [
                    'type' => 'string',
                    'nullable' => false,
                    'label' => 'OTP Delivery Channel',
                    'label_i18n_key' => 'settings.outbound_integrations.otp.delivery_channel.label',
                    'options' => ['whatsapp', 'sms'],
                    'default' => 'whatsapp',
                    'order' => 40,
                ],
                'otp.ttl_minutes' => [
                    'type' => 'integer',
                    'nullable' => false,
                    'label' => 'OTP TTL Minutes',
                    'label_i18n_key' => 'settings.outbound_integrations.otp.ttl_minutes.label',
                    'default' => 10,
                    'order' => 50,
                ],
                'otp.resend_cooldown_seconds' => [
                    'type' => 'integer',
                    'nullable' => false,
                    'label' => 'OTP Resend Cooldown Seconds',
                    'label_i18n_key' => 'settings.outbound_integrations.otp.resend_cooldown_seconds.label',
                    'default' => 60,
                    'order' => 60,
                ],
                'otp.max_attempts' => [
                    'type' => 'integer',
                    'nullable' => false,
                    'label' => 'OTP Max Attempts',
                    'label_i18n_key' => 'settings.outbound_integrations.otp.max_attempts.label',
                    'default' => 5,
                    'order' => 70,
                ],
            ],
            order: 40,
            labelI18nKey: 'settings.outbound_integrations.namespace.label',
            description: 'Tenant-owned webhook endpoints for queued outbound WhatsApp and OTP dispatch.',
            descriptionI18nKey: 'settings.outbound_integrations.namespace.description',
            icon: 'webhook',
        ));
    }
}
