<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Contracts;

interface PushSettingsMutationContract
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function patchNamespace(mixed $user, string $namespace, array $payload): array;
}
