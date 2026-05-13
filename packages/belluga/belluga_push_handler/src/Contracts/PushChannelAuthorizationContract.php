<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Contracts;

use Belluga\PushHandler\Models\Tenants\PushMessage;

interface PushChannelAuthorizationContract
{
    /**
     * @param  array<string, mixed>  $audience
     */
    public function assertCanPersist(string $scope, ?string $accountId, array $audience): void;

    public function assertCanDispatch(string $scope, ?string $accountId, PushMessage $message): void;
}
