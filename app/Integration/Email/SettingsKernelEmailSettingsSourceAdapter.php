<?php

declare(strict_types=1);

namespace App\Integration\Email;

use Belluga\Email\Contracts\EmailSettingsSourceContract;
use Belluga\Settings\Contracts\SettingsStoreContract;

class SettingsKernelEmailSettingsSourceAdapter implements EmailSettingsSourceContract
{
    public function __construct(
        private readonly SettingsStoreContract $settingsStore,
    ) {}

    /**
     * @return array{
     *   token:?string,
     *   from:?string,
     *   to:array<int, string>,
     *   cc:array<int, string>,
     *   bcc:array<int, string>,
     *   reply_to:array<int, string>
     * }
     */
    public function currentConfig(): array
    {
        $raw = $this->settingsStore->getNamespaceValue('tenant', 'resend_email');

        return $this->normalizeConfig(is_array($raw) ? $raw : []);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{
     *   token:?string,
     *   from:?string,
     *   to:array<int, string>,
     *   cc:array<int, string>,
     *   bcc:array<int, string>,
     *   reply_to:array<int, string>
     * }
     */
    public function normalizeConfig(array $raw): array
    {
        return [
            'token' => $this->normalizeText($raw['token'] ?? null),
            'from' => $this->normalizeText($raw['from'] ?? null),
            'to' => $this->normalizeEmailList($raw['to'] ?? []),
            'cc' => $this->normalizeEmailList($raw['cc'] ?? []),
            'bcc' => $this->normalizeEmailList($raw['bcc'] ?? []),
            'reply_to' => $this->normalizeEmailList($raw['reply_to'] ?? []),
        ];
    }

    /**
     * @param  array{
     *   token:?string,
     *   from:?string,
     *   to:array<int, string>,
     *   cc:array<int, string>,
     *   bcc:array<int, string>,
     *   reply_to:array<int, string>
     * }  $config
     */
    public function isConfigured(array $config): bool
    {
        return $config['token'] !== null
            && $config['from'] !== null
            && $config['to'] !== [];
    }

    /**
     * @param  array{
     *   token:?string,
     *   from:?string,
     *   to:array<int, string>,
     *   cc:array<int, string>,
     *   bcc:array<int, string>,
     *   reply_to:array<int, string>
     * }  $config
     * @return array<int, string>
     */
    public function missingRequiredFields(array $config): array
    {
        $missing = [];
        if ($config['token'] === null) {
            $missing[] = 'token';
        }
        if ($config['from'] === null) {
            $missing[] = 'from';
        }
        if ($config['to'] === []) {
            $missing[] = 'to';
        }

        return $missing;
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeEmailList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $candidate = trim($entry);
            if ($candidate === '') {
                continue;
            }

            $normalized[$candidate] = $candidate;
        }

        return array_values($normalized);
    }
}
