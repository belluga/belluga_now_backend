<?php

declare(strict_types=1);

namespace App\Integration\Email;

use Belluga\Settings\Contracts\SettingsNamespacePatchGuardContract;
use Belluga\Settings\Support\SettingsNamespaceDefinition;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mime\Address;

class ResendEmailSettingsPatchGuard implements SettingsNamespacePatchGuardContract
{
    public function __construct(
        private readonly SettingsKernelEmailSettingsSourceAdapter $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function guard(
        string $scope,
        mixed $user,
        string $namespace,
        array $payload,
        SettingsNamespaceDefinition $definition,
    ): void {
        if ($scope !== 'tenant' || $namespace !== 'resend_email') {
            return;
        }

        $current = $this->settings->currentConfig();
        $normalizedPatch = $this->normalizePatchPayload($payload, $definition->namespace);
        foreach ($normalizedPatch as $path => $value) {
            Arr::set($current, $path, $value);
        }

        $resolved = $this->settings->normalizeConfig($current);
        $errors = [];

        $from = $resolved['from'];
        if ($from !== null && ! $this->isValidSender($from)) {
            $errors['from'][] = 'Remetente invalido. Use "Nome <email@dominio.com>" ou apenas "email@dominio.com".';
        }

        $this->validateEmailList($resolved['to'], 'to', $errors, maxItems: 50);
        $this->validateEmailList($resolved['cc'], 'cc', $errors);
        $this->validateEmailList($resolved['bcc'], 'bcc', $errors);
        $this->validateEmailList($resolved['reply_to'], 'reply_to', $errors);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePatchPayload(array $payload, string $namespace): array
    {
        $normalized = [];

        foreach (Arr::dot(Arr::undot($payload)) as $key => $value) {
            if (! is_string($key) || trim($key) === '') {
                continue;
            }

            $trimmed = trim($key);
            $prefix = $namespace.'.';
            if (str_starts_with($trimmed, $prefix)) {
                $trimmed = substr($trimmed, strlen($prefix));
            }

            $normalized[$trimmed] = $value;
        }

        return $normalized;
    }

    private function isValidSender(string $value): bool
    {
        try {
            Address::create($value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<int, string>  $emails
     * @param  array<string, array<int, string>>  $errors
     */
    private function validateEmailList(array $emails, string $field, array &$errors, ?int $maxItems = null): void
    {
        if ($maxItems !== null && count($emails) > $maxItems) {
            $errors[$field][] = sprintf('O campo %s aceita no maximo %d destinatarios.', $field, $maxItems);
        }

        foreach ($emails as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors[$field][] = sprintf('O campo %s deve conter apenas emails validos.', $field);
                break;
            }
        }
    }
}
