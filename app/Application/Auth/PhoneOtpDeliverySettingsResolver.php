<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Models\Tenants\TenantSettings;
use Illuminate\Validation\ValidationException;

class PhoneOtpDeliverySettingsResolver
{
    private const int DEFAULT_TTL_MINUTES = 10;

    private const int DEFAULT_RESEND_COOLDOWN_SECONDS = 60;

    private const int DEFAULT_MAX_ATTEMPTS = 5;

    public function resolve(?string $requestedChannel = null): PhoneOtpDeliverySettings
    {
        $settings = TenantSettings::current();
        $raw = $settings?->getAttribute('outbound_integrations');
        $config = is_array($raw) ? $raw : [];
        $otp = $this->arrayAt($config, 'otp');
        $whatsapp = $this->arrayAt($config, 'whatsapp');

        $channel = $this->normalizeChannel($requestedChannel);
        $webhookUrl = $this->resolveWebhookUrl($channel, $otp, $whatsapp);

        if ($webhookUrl === null) {
            $this->throwMissingWebhookUrl($channel);
        }

        return new PhoneOtpDeliverySettings(
            webhookUrl: $webhookUrl,
            channel: $channel,
            ttlMinutes: $this->boundedInteger($otp['ttl_minutes'] ?? null, self::DEFAULT_TTL_MINUTES, 1, 30),
            resendCooldownSeconds: $this->boundedInteger(
                $otp['resend_cooldown_seconds'] ?? null,
                self::DEFAULT_RESEND_COOLDOWN_SECONDS,
                15,
                600
            ),
            maxAttempts: $this->boundedInteger($otp['max_attempts'] ?? null, self::DEFAULT_MAX_ATTEMPTS, 1, 10),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function arrayAt(array $config, string $key): array
    {
        $value = $config[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    private function normalizeChannel(mixed $value): string
    {
        $channel = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($channel, ['whatsapp', 'sms'], true) ? $channel : 'whatsapp';
    }

    /**
     * @param  array<string, mixed>  $otp
     * @param  array<string, mixed>  $whatsapp
     */
    private function resolveWebhookUrl(string $channel, array $otp, array $whatsapp): ?string
    {
        $otpWebhookUrl = $this->text($otp['webhook_url'] ?? null);

        if ($channel === 'sms') {
            return $otpWebhookUrl;
        }

        $whatsappWebhookUrl = $this->text($whatsapp['webhook_url'] ?? null);
        if ($this->boolean($otp['use_whatsapp_webhook'] ?? false) && $whatsappWebhookUrl !== null) {
            return $whatsappWebhookUrl;
        }

        $configuredOtpChannel = $this->normalizeConfiguredChannel($otp['delivery_channel'] ?? null);

        return $configuredOtpChannel === 'whatsapp' ? $otpWebhookUrl : $whatsappWebhookUrl;
    }

    private function normalizeConfiguredChannel(mixed $value): ?string
    {
        $channel = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($channel, ['whatsapp', 'sms'], true) ? $channel : null;
    }

    /**
     * @throws ValidationException
     */
    private function throwMissingWebhookUrl(string $channel): never
    {
        if ($channel === 'sms') {
            throw ValidationException::withMessages([
                'webhook_url' => ['Configure an SMS OTP webhook URL before starting SMS OTP delivery.'],
            ]);
        }

        throw ValidationException::withMessages([
            'webhook_url' => ['Configure an OTP or WhatsApp webhook URL before starting OTP delivery.'],
        ]);
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
        }

        return false;
    }

    private function boundedInteger(mixed $value, int $default, int $min, int $max): int
    {
        $candidate = is_int($value) ? $value : (is_numeric($value) ? (int) $value : $default);

        return max($min, min($max, $candidate));
    }
}
