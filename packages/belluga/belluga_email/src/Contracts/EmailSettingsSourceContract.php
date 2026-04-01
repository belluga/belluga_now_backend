<?php

declare(strict_types=1);

namespace Belluga\Email\Contracts;

interface EmailSettingsSourceContract
{
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
    public function currentConfig(): array;

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
    public function isConfigured(array $config): bool;

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
    public function missingRequiredFields(array $config): array;
}
