<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use Belluga\PushHandler\Models\Tenants\PushMessage;
use Belluga\PushHandler\Models\Tenants\PushMessageAction;
use MongoDB\BSON\UTCDateTime;

class PushMetricsService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordAction(PushMessage $message, array $payload, string $userId): ?PushMessageAction
    {
        $idempotencyKey = $payload['idempotency_key'];
        $existing = PushMessageAction::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return null;
        }

        $isUnique = ! $this->hasUniqueAction($message, $userId, $payload);

        $action = PushMessageAction::create([
            'push_message_id' => (string) $message->_id,
            'user_id' => $userId,
            'action' => $payload['action'],
            'step_index' => (int) $payload['step_index'],
            'button_key' => $payload['button_key'] ?? null,
            'device_id' => $payload['device_id'] ?? null,
            'metadata' => $payload['metadata'] ?? [],
            'context' => $payload['context'] ?? [],
            'idempotency_key' => $idempotencyKey,
            'created_at' => new UTCDateTime,
        ]);

        $this->updateAggregates($message, $action, $isUnique);

        return $action;
    }

    private function updateAggregates(PushMessage $message, PushMessageAction $action, bool $isUnique): void
    {
        $metrics = $message->metrics ?? [];
        $actionType = $action->action;
        $stepIndex = (string) $action->step_index;

        $metrics['step_view_counts'] ??= [];
        $metrics['button_click_counts'] ??= [];

        if ($actionType === 'step_viewed') {
            $metrics['step_view_counts'][$stepIndex] = ($metrics['step_view_counts'][$stepIndex] ?? 0) + 1;
        }

        if ($actionType === 'clicked' && $action->button_key) {
            $metrics['button_click_counts'][$action->button_key] = ($metrics['button_click_counts'][$action->button_key] ?? 0) + 1;
        }

        $metrics = $this->incrementMetric($metrics, $actionType, $isUnique);

        $message->metrics = $metrics;
        $message->save();
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    private function incrementMetric(array $metrics, string $actionType, bool $isUnique): array
    {
        $map = [
            'opened' => ['opened_count', 'unique_opened_count'],
            'clicked' => ['clicked_count', 'unique_clicked_count'],
            'dismissed' => ['dismissed_count', 'unique_dismissed_count'],
        ];

        if ($actionType === 'delivered') {
            $metrics['delivered_count'] = ($metrics['delivered_count'] ?? 0) + 1;

            return $metrics;
        }

        if (! isset($map[$actionType])) {
            return $metrics;
        }

        [$countKey, $uniqueKey] = $map[$actionType];

        $metrics[$countKey] = ($metrics[$countKey] ?? 0) + 1;

        if ($isUnique) {
            $metrics[$uniqueKey] = ($metrics[$uniqueKey] ?? 0) + 1;
        }

        return $metrics;
    }

    private function hasUniqueAction(PushMessage $message, string $userId, array $payload): bool
    {
        $query = PushMessageAction::query()
            ->where('push_message_id', (string) $message->_id)
            ->where('user_id', $userId)
            ->where('action', $payload['action'])
            ->where('step_index', $payload['step_index']);

        if (! empty($payload['button_key'])) {
            $query->where('button_key', $payload['button_key']);
        }

        $context = $payload['context'] ?? [];
        if ($context !== []) {
            foreach ($context as $key => $value) {
                $query->where("context.$key", $value);
            }
        }

        return $query->exists();
    }
}
