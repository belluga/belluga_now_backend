<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Requests;

use Belluga\PushHandler\Models\Tenants\PushMessage;
use Belluga\PushHandler\Services\PushRouteCatalog;
use Belluga\PushHandler\Services\PushSettingsKernelBridge;

class PushMessageUpdateRequest extends PushMessageRequest
{
    public function rules(PushSettingsKernelBridge $pushSettings, PushRouteCatalog $routeCatalog): array
    {
        return $this->messageRules($pushSettings, $routeCatalog, true);
    }

    protected function resolveMessageType(): ?string
    {
        $type = $this->input('type');
        if (is_string($type) && $type !== '') {
            return $type;
        }

        $messageId = (string) $this->route('push_message_id');
        if ($messageId === '') {
            return null;
        }

        $message = PushMessage::query()
            ->where('scope', 'tenant')
            ->where('_id', $messageId)
            ->first();

        $existingType = $message?->type ?? null;

        return is_string($existingType) ? $existingType : null;
    }
}
