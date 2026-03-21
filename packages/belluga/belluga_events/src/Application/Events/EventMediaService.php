<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Events;

use Belluga\Events\Contracts\EventTenantContextContract;
use Belluga\Events\Models\Tenants\Event;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class EventMediaService
{
    private const LEGACY_PUBLIC_PATH_PREFIX = '/events';

    private const CANONICAL_PUBLIC_PATH_PREFIX = '/api/v1/media/events';

    public function __construct(
        private readonly EventTenantContextContract $tenantContext,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function applyUploads(Request $request, Event $event): array
    {
        $baseUrl = $request->getSchemeAndHttpHost();
        $removeCover = $request->boolean('remove_cover');

        if (! $request->hasFile('cover') && ! $removeCover) {
            return [];
        }

        $updates = [];
        $event->updated_at = now();

        if ($request->hasFile('cover')) {
            $coverUrl = $this->storeFile(
                $request->file('cover'),
                $event,
                'cover',
                $baseUrl
            );
            $updates['thumb'] = [
                'type' => 'image',
                'data' => [
                    'url' => $coverUrl,
                ],
            ];
        } elseif ($removeCover) {
            $this->deleteExisting($event, 'cover');
            $updates['thumb'] = null;
        }

        if ($updates !== []) {
            $event->fill($updates);
            $event->save();
            $event->refresh();
        }

        return $updates;
    }

    private function storeFile(
        UploadedFile $file,
        Event $event,
        string $kind,
        string $baseUrl
    ): string {
        $extension = $file->getClientOriginalExtension() ?: 'png';
        $fileName = "{$kind}.{$extension}";

        $this->deleteExisting($event, $kind);
        Storage::disk('public')->putFileAs($this->baseDirectory($event), $file, $fileName);

        return $this->buildPublicUrl($baseUrl, $event, $kind);
    }

    public function resolveMediaPathForBaseUrl(
        Event $event,
        string $kind,
        ?string $baseUrl = null,
    ): ?string {
        $baseDir = $this->baseDirectory($event);
        foreach ($this->allowedExtensions() as $extension) {
            $path = "{$baseDir}/{$kind}.{$extension}";
            if (Storage::disk('public')->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    public function buildPublicUrl(
        string $baseUrl,
        Event $event,
        string $kind,
        string|int|null $version = null,
    ): string {
        $eventId = (string) $event->_id;
        $base = rtrim($baseUrl, '/');
        $resolvedVersion = $version ?? ($event->updated_at?->getTimestamp() ?? time());

        return "{$base}".self::CANONICAL_PUBLIC_PATH_PREFIX."/{$eventId}/{$kind}?v={$resolvedVersion}";
    }

    public function normalizePublicUrl(
        string $baseUrl,
        Event $event,
        string $kind,
        ?string $rawUrl,
    ): ?string {
        $value = is_string($rawUrl) ? trim($rawUrl) : '';
        if ($value === '') {
            return null;
        }

        $path = parse_url($value, PHP_URL_PATH);
        if (! is_string($path) || trim($path) === '') {
            return $value;
        }

        $eventId = (string) $event->_id;
        $legacyPath = self::LEGACY_PUBLIC_PATH_PREFIX."/{$eventId}/{$kind}";
        $canonicalPath = self::CANONICAL_PUBLIC_PATH_PREFIX."/{$eventId}/{$kind}";
        if ($path !== $legacyPath && $path !== $canonicalPath) {
            return $value;
        }

        $version = $this->extractVersionFromUri($value)
            ?? ($event->updated_at?->getTimestamp() ?? time());

        return $this->buildPublicUrl($baseUrl, $event, $kind, $version);
    }

    private function deleteExisting(Event $event, string $kind): void
    {
        $baseDir = $this->baseDirectory($event);
        foreach ($this->allowedExtensions() as $extension) {
            $path = "{$baseDir}/{$kind}.{$extension}";
            if (Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function allowedExtensions(): array
    {
        return ['jpg', 'jpeg', 'png', 'webp'];
    }

    private function baseDirectory(Event $event): string
    {
        $tenantId = trim($this->tenantContext->resolveCurrentTenantId());
        if ($tenantId === '') {
            $tenantId = 'tenant';
        }
        $eventId = (string) $event->_id;

        return "tenants/{$tenantId}/events/{$eventId}";
    }

    private function extractVersionFromUri(string $value): ?string
    {
        $query = parse_url($value, PHP_URL_QUERY);
        if (! is_string($query) || trim($query) === '') {
            return null;
        }

        parse_str($query, $parameters);
        $version = $parameters['v'] ?? null;
        if (! is_scalar($version)) {
            return null;
        }

        $normalized = trim((string) $version);

        return $normalized === '' ? null : $normalized;
    }
}
