<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Contracts;

use Belluga\PushHandler\Models\Tenants\PushMessage;

interface PushChannelTargetResolverContract
{
    public function resolveTopic(PushMessage $message): ?string;
}
