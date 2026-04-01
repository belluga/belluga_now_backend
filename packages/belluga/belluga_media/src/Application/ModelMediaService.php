<?php

declare(strict_types=1);

namespace Belluga\Media\Application;

use Belluga\Media\Contracts\TenantMediaScopeResolverContract;
use Belluga\Media\Support\MediaModelDefinition;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class ModelMediaService
{
    public function __construct(
        private readonly TenantMediaScopeResolverContract $tenantScopeResolver,
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function applyUploads(Request $request, object $model, MediaModelDefinition $definition): array
    {
        $updates = [];
        $baseUrl = $request->getSchemeAndHttpHost();

        if ($this->hasMutation($request, $definition)) {
            $model->updated_at = now();
        }

        foreach ($definition->slots as $slot) {
            $removeFlag = "remove_{$slot}";
            if ($request->hasFile($slot)) {
                $file = $request->file($slot);
                if ($file instanceof UploadedFile) {
                    $updates["{$slot}_url"] = $this->storeFile($file, $model, $slot, $baseUrl, $definition);
                }

                continue;
            }

            if (! $request->boolean($removeFlag)) {
                continue;
            }

            $this->deleteExisting($model, $slot, $baseUrl, $definition);
            $updates["{$slot}_url"] = null;
        }

        if ($updates !== []) {
            $model->fill($updates);
            $model->save();
            $model->refresh();
        }

        return $updates;
    }

    public function resolveMediaPath(object $model, string $kind, MediaModelDefinition $definition): ?string
    {
        return $this->resolveMediaPathForBaseUrl($model, $kind, $definition, null);
    }

    public function resolveMediaPathForBaseUrl(
        object $model,
        string $kind,
        MediaModelDefinition $definition,
        ?string $baseUrl,
    ): ?string {
        $baseDir = $this->baseDirectory($model, $definition, $baseUrl);
        foreach ($definition->allowedExtensions as $extension) {
            $path = "{$baseDir}/{$kind}.{$extension}";
            if (Storage::disk('public')->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    public function buildPublicUrl(
        string $baseUrl,
        object $model,
        string $kind,
        MediaModelDefinition $definition,
        string|int|null $version = null,
    ): string {
        $modelId = $this->resolveModelId($model);
        $base = rtrim($baseUrl, '/');
        $resolvedVersion = $version ?? $this->resolveModelVersion($model);
        $canonicalPrefix = $this->normalizePrefix($definition->canonicalPublicPathPrefix);

        return "{$base}{$canonicalPrefix}/{$modelId}/{$kind}?v={$resolvedVersion}";
    }

    public function normalizePublicUrl(
        string $baseUrl,
        object $model,
        string $kind,
        MediaModelDefinition $definition,
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

        $modelId = $this->resolveModelId($model);
        $legacyPath = $this->normalizePrefix($definition->legacyPublicPathPrefix)."/{$modelId}/{$kind}";
        $canonicalPath = $this->normalizePrefix($definition->canonicalPublicPathPrefix)."/{$modelId}/{$kind}";
        if ($path !== $legacyPath && $path !== $canonicalPath) {
            return $value;
        }

        $version = $this->extractVersionFromUri($value) ?? $this->resolveModelVersion($model);

        return $this->buildPublicUrl($baseUrl, $model, $kind, $definition, $version);
    }

    private function storeFile(
        UploadedFile $file,
        object $model,
        string $kind,
        string $baseUrl,
        MediaModelDefinition $definition,
    ): string {
        $extension = strtolower(trim((string) $file->getClientOriginalExtension()));
        if ($extension === '') {
            $extension = 'png';
        }
        $fileName = "{$kind}.{$extension}";

        $this->deleteExisting($model, $kind, $baseUrl, $definition);

        Storage::disk('public')->putFileAs(
            $this->baseDirectory($model, $definition, $baseUrl),
            $file,
            $fileName
        );

        return $this->buildPublicUrl($baseUrl, $model, $kind, $definition);
    }

    private function deleteExisting(
        object $model,
        string $kind,
        ?string $baseUrl,
        MediaModelDefinition $definition,
    ): void {
        $baseDir = $this->baseDirectory($model, $definition, $baseUrl);
        foreach ($definition->allowedExtensions as $extension) {
            $path = "{$baseDir}/{$kind}.{$extension}";
            if (Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        }
    }

    private function baseDirectory(object $model, MediaModelDefinition $definition, ?string $baseUrl): string
    {
        $tenantScope = trim((string) ($this->tenantScopeResolver->resolveTenantScope($baseUrl) ?? ''));
        if ($tenantScope === '') {
            $tenantScope = trim($definition->tenantScopeFallback);
        }
        if ($tenantScope === '') {
            $tenantScope = 'landlord';
        }

        $storageDirectory = trim($definition->storageDirectory, '/');
        if ($storageDirectory === '') {
            throw new RuntimeException('MediaModelDefinition.storageDirectory cannot be empty.');
        }

        return "tenants/{$tenantScope}/{$storageDirectory}/".$this->resolveModelId($model);
    }

    private function normalizePrefix(string $prefix): string
    {
        $normalized = '/'.trim($prefix, '/');

        return $normalized === '/' ? '' : $normalized;
    }

    private function resolveModelId(object $model): string
    {
        $candidate = null;
        if (method_exists($model, 'getAttribute')) {
            $candidate = $model->getAttribute('_id');
            if ($candidate === null) {
                $candidate = $model->getAttribute('id');
            }
        }
        if ($candidate === null) {
            $candidate = $model->_id ?? $model->id ?? null;
        }
        if ($candidate === null && method_exists($model, 'getKey')) {
            $candidate = $model->getKey();
        }

        $value = trim((string) $candidate);
        if ($value === '') {
            throw new RuntimeException('Model identifier is required for media operations.');
        }

        return $value;
    }

    private function resolveModelVersion(object $model): int
    {
        $updatedAt = null;
        if (method_exists($model, 'getAttribute')) {
            $updatedAt = $model->getAttribute('updated_at');
        }
        if ($updatedAt === null) {
            $updatedAt = $model->updated_at ?? null;
        }

        if ($updatedAt instanceof DateTimeInterface) {
            return $updatedAt->getTimestamp();
        }

        if (is_numeric($updatedAt)) {
            return (int) $updatedAt;
        }

        return time();
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

    private function hasMutation(Request $request, MediaModelDefinition $definition): bool
    {
        foreach ($definition->slots as $slot) {
            if ($request->hasFile($slot) || $request->boolean("remove_{$slot}")) {
                return true;
            }
        }

        return false;
    }
}
