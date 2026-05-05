<?php

declare(strict_types=1);

namespace App\Integration\Settings;

use Belluga\Settings\Contracts\SettingsRegistryContract;
use Belluga\Settings\Support\SettingsNamespaceDefinition;

class PhoneOtpReviewAccessSettingsNamespaceRegistrar
{
    public function register(SettingsRegistryContract $registry): void
    {
        if ($registry->find('phone_otp_review_access', 'tenant') !== null) {
            return;
        }

        $registry->register(new SettingsNamespaceDefinition(
            namespace: 'phone_otp_review_access',
            scope: 'tenant',
            label: 'Phone OTP Review Access',
            groupLabel: 'Identity',
            ability: 'tenant-public-auth-settings:update',
            fields: [
                'phone_e164' => [
                    'type' => 'string',
                    'nullable' => true,
                    'label' => 'Review Phone (E.164)',
                    'label_i18n_key' => 'settings.phone_otp_review_access.phone_e164.label',
                    'default' => null,
                    'order' => 10,
                ],
                'code_hash' => [
                    'type' => 'string',
                    'nullable' => true,
                    'label' => 'Review Code Hash',
                    'label_i18n_key' => 'settings.phone_otp_review_access.code_hash.label',
                    'default' => null,
                    'order' => 20,
                ],
            ],
            order: 26,
            labelI18nKey: 'settings.phone_otp_review_access.namespace.label',
            description: 'Backend-private reviewer access allowlist for phone OTP review login.',
            descriptionI18nKey: 'settings.phone_otp_review_access.namespace.description',
            icon: 'shield',
        ));
    }
}
