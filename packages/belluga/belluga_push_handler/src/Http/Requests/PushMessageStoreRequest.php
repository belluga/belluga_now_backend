<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Requests;

use Belluga\PushHandler\Services\PushRouteCatalog;
use Belluga\PushHandler\Services\PushSettingsKernelBridge;

class PushMessageStoreRequest extends PushMessageRequest
{
    public function rules(PushSettingsKernelBridge $pushSettings, PushRouteCatalog $routeCatalog): array
    {
        return $this->messageRules($pushSettings, $routeCatalog, false);
    }

    protected function resolveMessageType(): ?string
    {
        $type = $this->input('type');

        return is_string($type) && $type !== '' ? $type : null;
    }
}
