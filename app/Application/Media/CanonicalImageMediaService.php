<?php

declare(strict_types=1);

namespace App\Application\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class CanonicalImageMediaService
{
    public function storeUpload(
        CanonicalImageDefinition $definition,
        UploadedFile $file,
        string $baseUrl,
    ): string {
        $candidate = $this->primaryStorageCandidate(
            $definition,
            $this->resolveUploadedExtension($file),
        );

        $this->deleteAllCandidates($definition);
        Storage::disk('public')->putFileAs(dirname($candidate), $file, basename($candidate));

        return $this->buildPublicUrl(
            $baseUrl,
            $definition,
            $this->resolveMediaVersion($candidate),
        );
    }

    public function storeContent(
        CanonicalImageDefinition $definition,
        string $content,
        string $baseUrl,
    ): string {
        $candidate = $this->primaryStorageCandidate($definition);

        $this->deleteAllCandidates($definition);
        Storage::disk('public')->put($candidate, $content);

        return $this->buildPublicUrl(
            $baseUrl,
            $definition,
            $this->resolveMediaVersion($candidate),
        );
    }

    public function resolveStoragePath(
        CanonicalImageDefinition $definition,
        ?string $rawUrl,
    ): ?string {
        $value = is_string($rawUrl) ? trim($rawUrl) : '';
        if ($value === '') {
            return null;
        }

        $path = parse_url($value, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return null;
        }

        if ($path === $definition->canonicalPublicPath || in_array($path, $definition->legacyPublicPaths, true)) {
            return $this->resolveCurrentStoragePath($definition);
        }

        foreach ($definition->storageCandidates as $candidate) {
            if ($path === '/storage/'.$candidate && Storage::disk('public')->exists($candidate)) {
                return $candidate;
            }
        }

        if (! str_starts_with($path, '/storage/')) {
            return null;
        }

        $storagePath = ltrim(substr($path, strlen('/storage/')), '/');

        return Storage::disk('public')->exists($storagePath) ? $storagePath : null;
    }

    public function buildPublicUrl(
        string $baseUrl,
        CanonicalImageDefinition $definition,
        string|int|null $version = null,
    ): string {
        $base = rtrim($baseUrl, '/');
        $resolvedVersion = $version ?? time();

        return "{$base}{$definition->canonicalPublicPath}?v={$resolvedVersion}";
    }

    public function normalizePublicUrl(
        string $baseUrl,
        CanonicalImageDefinition $definition,
        ?string $rawUrl,
    ): ?string {
        $value = is_string($rawUrl) ? trim($rawUrl) : '';
        if ($value === '') {
            return null;
        }

        $path = parse_url($value, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return $value;
        }

        $isStoragePath = false;
        foreach ($definition->storageCandidates as $candidate) {
            if ($path === '/storage/'.$candidate) {
                $isStoragePath = true;
                break;
            }
        }

        if ($path !== $definition->canonicalPublicPath
            && ! in_array($path, $definition->legacyPublicPaths, true)
            && ! $isStoragePath) {
            return $value;
        }

        $version = $this->extractVersionFromUri($value)
            ?? $this->resolveCurrentVersion($definition);

        return $this->buildPublicUrl($baseUrl, $definition, $version);
    }

    private function resolveCurrentVersion(CanonicalImageDefinition $definition): ?string
    {
        $path = $this->resolveCurrentStoragePath($definition);
        if ($path === null) {
            return null;
        }

        return $this->resolveMediaVersion($path);
    }

    private function resolveCurrentStoragePath(CanonicalImageDefinition $definition): ?string
    {
        foreach ($definition->storageCandidates as $candidate) {
            if (Storage::disk('public')->exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function deleteAllCandidates(CanonicalImageDefinition $definition): void
    {
        foreach ($definition->storageCandidates as $candidate) {
            if (Storage::disk('public')->exists($candidate)) {
                Storage::disk('public')->delete($candidate);
            }
        }
    }

    private function resolveMediaVersion(string $relativePath): string
    {
        $absolutePath = Storage::disk('public')->path($relativePath);
        $fingerprint = @md5_file($absolutePath);

        if (is_string($fingerprint) && $fingerprint !== '') {
            return substr($fingerprint, 0, 16);
        }

        return (string) Storage::disk('public')->lastModified($relativePath);
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

    private function primaryStorageCandidate(
        CanonicalImageDefinition $definition,
        ?string $preferredExtension = null,
    ): string {
        $normalizedPreferred = strtolower(trim((string) $preferredExtension));

        if ($normalizedPreferred !== '') {
            foreach ($definition->storageCandidates as $candidate) {
                if (strtolower(pathinfo($candidate, PATHINFO_EXTENSION)) === $normalizedPreferred) {
                    return $candidate;
                }
            }
        }

        $candidate = $definition->storageCandidates[0] ?? null;
        if (! is_string($candidate) || trim($candidate) === '') {
            throw new RuntimeException('CanonicalImageDefinition.storageCandidates cannot be empty.');
        }

        return $candidate;
    }

    private function resolveUploadedExtension(UploadedFile $file): string
    {
        $extension = strtolower(trim((string) $file->getClientOriginalExtension()));

        return $extension !== '' ? $extension : 'png';
    }
}
