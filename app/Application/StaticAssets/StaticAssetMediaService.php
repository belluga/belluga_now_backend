<?php

declare(strict_types=1);

namespace App\Application\StaticAssets;

use App\Models\Landlord\Tenant;
use App\Models\Tenants\StaticAsset;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class StaticAssetMediaService
{
    /**
     * @return array<string, string|null>
     */
    public function applyUploads(Request $request, StaticAsset $asset): array
    {
        $updates = [];
        $baseUrl = $request->getSchemeAndHttpHost();
        $removeAvatar = $request->boolean('remove_avatar');
        $removeCover = $request->boolean('remove_cover');

        if ($request->hasFile('avatar') || $request->hasFile('cover') || $removeAvatar || $removeCover) {
            $asset->updated_at = now();
        }

        if ($request->hasFile('avatar')) {
            $updates['avatar_url'] = $this->storeFile(
                $request->file('avatar'),
                $asset,
                'avatar',
                $baseUrl
            );
        } elseif ($removeAvatar) {
            $this->deleteExisting($asset, 'avatar');
            $updates['avatar_url'] = null;
        }

        if ($request->hasFile('cover')) {
            $updates['cover_url'] = $this->storeFile(
                $request->file('cover'),
                $asset,
                'cover',
                $baseUrl
            );
        } elseif ($removeCover) {
            $this->deleteExisting($asset, 'cover');
            $updates['cover_url'] = null;
        }

        if ($updates !== []) {
            $asset->fill($updates);
            $asset->save();
            $asset->refresh();
        }

        return $updates;
    }

    private function storeFile(
        UploadedFile $file,
        StaticAsset $asset,
        string $kind,
        string $baseUrl
    ): string {
        $extension = $file->getClientOriginalExtension() ?: 'png';
        $fileName = "{$kind}.{$extension}";

        $this->deleteExisting($asset, $kind);

        Storage::disk('public')->putFileAs($this->baseDirectory($asset), $file, $fileName);

        return $this->buildPublicUrl($baseUrl, $asset, $kind);
    }

    public function resolveMediaPath(StaticAsset $asset, string $kind): ?string
    {
        $baseDir = $this->baseDirectory($asset);
        foreach ($this->allowedExtensions() as $extension) {
            $path = "{$baseDir}/{$kind}.{$extension}";
            if (Storage::disk('public')->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    public function buildPublicUrl(string $baseUrl, StaticAsset $asset, string $kind): string
    {
        $assetId = (string) $asset->_id;
        $base = rtrim($baseUrl, '/');
        $version = $asset->updated_at?->getTimestamp() ?? time();

        return "{$base}/static-assets/{$assetId}/{$kind}?v={$version}";
    }

    private function deleteExisting(StaticAsset $asset, string $kind): void
    {
        $baseDir = $this->baseDirectory($asset);
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

    private function baseDirectory(StaticAsset $asset): string
    {
        $tenantSlug = Tenant::current()?->slug ?? 'landlord';
        $assetId = (string) $asset->_id;

        return "tenants/{$tenantSlug}/static_assets/{$assetId}";
    }
}
