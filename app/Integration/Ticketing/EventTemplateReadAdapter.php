<?php

declare(strict_types=1);

namespace App\Integration\Ticketing;

use Belluga\Ticketing\Models\Tenants\TicketEventTemplate;
use Belluga\Ticketing\Contracts\EventTemplateReadContract;

class EventTemplateReadAdapter implements EventTemplateReadContract
{
    public function findTemplateSnapshot(string $templateId): ?array
    {
        /** @var TicketEventTemplate|null $template */
        $template = TicketEventTemplate::query()
            ->where('template_key', $templateId)
            ->where('status', 'active')
            ->orderByDesc('version')
            ->first();

        if (! $template) {
            return null;
        }

        return [
            'template_id' => (string) $template->template_key,
            'version' => (int) ($template->version ?? 1),
            'defaults' => is_array($template->defaults ?? null) ? $template->defaults : [],
            'field_states' => is_array($template->field_states ?? null) ? $template->field_states : [],
            'hidden_fields' => is_array($template->hidden_fields ?? null) ? $template->hidden_fields : [],
            'metadata' => is_array($template->metadata ?? null) ? $template->metadata : [],
        ];
    }
}
