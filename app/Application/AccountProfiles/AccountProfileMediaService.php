<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountProfile;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class AccountProfileMediaService
{
    /**
     * @return array<string, string>
     */
    public function applyUploads(Request $request, AccountProfile $profile): array
    {
        $updates = [];
        $baseUrl = $request->getSchemeAndHttpHost();
        $removeAvatar = $request->boolean('remove_avatar');
        $removeCover = $request->boolean('remove_cover');

        if ($request->hasFile('avatar') || $request->hasFile('cover') || $removeAvatar || $removeCover) {
            $profile->updated_at = now();
        }

        if ($request->hasFile('avatar')) {
            $updates['avatar_url'] = $this->storeFile(
                $request->file('avatar'),
                $profile,
                'avatar',
                $baseUrl
            );
        } elseif ($removeAvatar) {
            $this->deleteExisting($profile, 'avatar');
            $updates['avatar_url'] = null;
        }

        if ($request->hasFile('cover')) {
            $updates['cover_url'] = $this->storeFile(
                $request->file('cover'),
                $profile,
                'cover',
                $baseUrl
            );
        } elseif ($removeCover) {
            $this->deleteExisting($profile, 'cover');
            $updates['cover_url'] = null;
        }

        if (! empty($updates)) {
            $profile->fill($updates);
            $profile->save();
            $profile->refresh();
        }

        return $updates;
    }

    private function storeFile(
        UploadedFile $file,
        AccountProfile $profile,
        string $kind,
        string $baseUrl
    ): string {
        $extension = $file->getClientOriginalExtension() ?: 'png';
        $fileName = "{$kind}.{$extension}";
        $path = $this->buildStoragePath($profile, $fileName);

        $this->deleteExisting($profile, $kind);

        Storage::disk('public')->putFileAs($this->baseDirectory($profile), $file, $fileName);

        return $this->buildPublicUrl($baseUrl, $profile, $kind);
    }

    public function resolveMediaPath(AccountProfile $profile, string $kind): ?string
    {
        $baseDir = $this->baseDirectory($profile);
        foreach ($this->allowedExtensions() as $extension) {
            $path = "{$baseDir}/{$kind}.{$extension}";
            if (Storage::disk('public')->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    public function buildPublicUrl(string $baseUrl, AccountProfile $profile, string $kind): string
    {
        $profileId = (string) $profile->_id;
        $base = rtrim($baseUrl, '/');
        $version = $profile->updated_at?->getTimestamp() ?? time();

        return "{$base}/account-profiles/{$profileId}/{$kind}?v={$version}";
    }

    private function deleteExisting(AccountProfile $profile, string $kind): void
    {
        $baseDir = $this->baseDirectory($profile);
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

    private function buildStoragePath(AccountProfile $profile, string $fileName): string
    {
        return $this->baseDirectory($profile) . '/' . $fileName;
    }

    private function baseDirectory(AccountProfile $profile): string
    {
        $tenantSlug = Tenant::current()?->slug ?? 'landlord';
        $profileId = (string) $profile->_id;

        return "tenants/{$tenantSlug}/account_profiles/{$profileId}";
    }
}
