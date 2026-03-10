<?php

declare(strict_types=1);

namespace App\Application\Media;

use App\Models\Landlord\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class MapFilterImageStorageService
{
    /**
     * @return array{key: string, image_uri: string}
     */
    public function store(
        string $key,
        UploadedFile $image,
        string $baseUrl
    ): array {
        $normalizedKey = $this->normalizeKey($key);
        $extension = $this->resolveExtension($image);
        $directory = $this->baseDirectory();

        $this->deleteExisting($directory, $normalizedKey);

        $fileName = "{$normalizedKey}.{$extension}";
        Storage::disk('public')->putFileAs($directory, $image, $fileName);

        $relativePath = "{$directory}/{$fileName}";
        $publicPath = Storage::disk('public')->url($relativePath);

        return [
            'key' => $normalizedKey,
            'image_uri' => $this->buildAbsoluteUrl($baseUrl, $publicPath),
        ];
    }

    public function normalizeKey(string $rawKey): string
    {
        return strtolower(trim($rawKey));
    }

    private function baseDirectory(): string
    {
        $tenantSlug = Tenant::current()?->slug ?? 'landlord';

        return "tenants/{$tenantSlug}/map_filters";
    }

    private function resolveExtension(UploadedFile $file): string
    {
        $mime = strtolower((string) $file->getMimeType());
        if ($mime === 'image/jpeg') {
            return 'jpg';
        }
        if ($mime === 'image/webp') {
            return 'webp';
        }

        return 'png';
    }

    private function deleteExisting(string $directory, string $key): void
    {
        foreach (['png', 'jpg', 'jpeg', 'webp'] as $extension) {
            $path = "{$directory}/{$key}.{$extension}";
            if (Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        }
    }

    private function buildAbsoluteUrl(string $baseUrl, string $path): string
    {
        $base = rtrim($baseUrl, '/');
        $relative = '/' . ltrim($path, '/');

        return $base . $relative;
    }
}

