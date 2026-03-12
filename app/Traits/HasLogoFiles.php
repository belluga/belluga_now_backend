<?php

namespace App\Traits;

use App\Models\Landlord\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Laravel\Facades\Image;

trait HasLogoFiles
{
    protected array $logoKeys = ['light_logo_uri', 'dark_logo_uri', 'light_icon_uri', 'dark_icon_uri', 'favicon_uri'];

    protected function getScopedPathProperty(): string
    {
        $tenant = Tenant::current();

        if ($tenant) {
            // Using a 'tenants' subfolder is a good practice for organization.
            return "tenants/{$tenant->slug}";
        }

        return 'landlord';
    }

    private function _saveContentToPublicDisk(string $content, string $path): string
    {
        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
        Storage::disk('public')->put($path, $content);
        Storage::forgetDisk('public'); // Invalidate cache after writing

        return Storage::disk('public')->url($path);
    }

    protected function processLogoUploads(Request $request): array
    {
        $urls = [];
        foreach ($this->logoKeys as $key) {
            // This logic handles differently structured incoming requests
            $fileKey = "branding_data.logo_settings.{$key}";
            if (! $request->hasFile($fileKey)) {
                $fileKey = "logo_settings.{$key}";
            }

            if ($request->hasFile($fileKey)) {
                $urls[$key] = $this->storeFile($request->file($fileKey), $key);
            }
        }

        return $urls;
    }

    protected function storeFile(UploadedFile $file, string $key): string
    {
        $baseName = str_ends_with($key, '_uri') ? substr($key, 0, -4) : $key;
        $extension = $key === 'favicon_uri'
            ? 'ico'
            : ($file->getClientOriginalExtension() ?: 'png');

        // Uses the dynamic path
        $path = "{$this->getScopedPathProperty()}/logos/{$baseName}.{$extension}";
        $fileContent = file_get_contents($file->getRealPath());

        return $this->_saveContentToPublicDisk($fileContent, $path);
    }

    protected function generatePwaIconVariants(UploadedFile $sourceFile): array
    {
        $sourceUri = $this->storeFile($sourceFile, 'pwa_icon_source');
        $sourcePath = $sourceFile->getRealPath();
        $baseDir = "{$this->getScopedPathProperty()}/pwa";
        Storage::disk('public')->makeDirectory($baseDir);

        $paths = [
            'icon192' => "{$baseDir}/icon-192x192.png",
            'icon512' => "{$baseDir}/icon-512x512.png",
            'maskable512' => "{$baseDir}/icon-maskable-512x512.png",
        ];

        $canvas192 = Image::create(192, 192)->place(Image::read($sourcePath)->contain(192, 192), 'center');
        $icon192Path = $this->_saveContentToPublicDisk($canvas192->toPng()->toString(), $paths['icon192']);

        $canvas512 = Image::create(512, 512)->place(Image::read($sourcePath)->contain(512, 512), 'center');
        $icon512Path = $this->_saveContentToPublicDisk($canvas512->toPng()->toString(), $paths['icon512']);

        $canvasMaskable = Image::create(512, 512)->place(Image::read($sourcePath)->contain(410, 410), 'center');
        $icon512MaskablePath = $this->_saveContentToPublicDisk($canvasMaskable->toPng()->toString(), $paths['maskable512']);

        return [
            'source_uri' => $sourceUri,
            'icon192_uri' => $icon192Path,
            'icon512_uri' => $icon512Path,
            'icon_maskable512_uri' => $icon512MaskablePath,
        ];
    }
}
