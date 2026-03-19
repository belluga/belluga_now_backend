<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Operations;

use Belluga\Events\Contracts\EventAsyncJobSignaturesContract;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;

class EventDlqAlertService
{
    public function __construct(
        private readonly EventAsyncJobSignaturesContract $jobSignatures
    ) {}

    public function handle(JobFailed $event): void
    {
        $jobClass = $this->resolveJobClass($event);
        if (! $this->isEventJob($jobClass)) {
            return;
        }

        Log::critical('events_async_dlq_alert', [
            'job_class' => $jobClass,
            'connection' => $event->connectionName,
            'queue' => $event->job?->getQueue(),
            'exception' => $event->exception->getMessage(),
            'failed_at' => now()->toISOString(),
            'payload_uuid' => $this->resolvePayloadUuid($event),
        ]);
    }

    private function resolveJobClass(JobFailed $event): string
    {
        $payload = $event->job?->payload();
        if (is_array($payload) && isset($payload['displayName']) && is_string($payload['displayName'])) {
            return $payload['displayName'];
        }

        $resolved = $event->job?->resolveName();

        return is_string($resolved) ? $resolved : 'unknown';
    }

    private function resolvePayloadUuid(JobFailed $event): ?string
    {
        $payload = $event->job?->payload();
        if (! is_array($payload)) {
            return null;
        }

        $uuid = $payload['uuid'] ?? null;

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    private function isEventJob(string $jobClass): bool
    {
        if ($jobClass === '') {
            return false;
        }

        foreach ($this->jobSignatures->signatures() as $signature) {
            if ($signature === '') {
                continue;
            }
            if (str_contains($jobClass, $signature)) {
                return true;
            }
        }

        return false;
    }
}
