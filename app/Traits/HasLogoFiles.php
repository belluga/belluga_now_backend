<?php

namespace App\Traits;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Laravel\Facades\Image;

trait HasLogoFiles {

    protected array $logoKeys = ['light_logo_uri', 'dark_logo_uri', 'light_icon_uri', 'dark_icon_uri', 'favicon_uri'];

    protected function processLogoUploads(Request $request): array
    {
        $urls = [];
        foreach ($this->logoKeys as $key) {
            if($request->hasFile("logo_settings.{$key}")){
                $fileKey = "logo_settings.{$key}";
            }else{
                $fileKey = "branding_data.logo_settings.{$key}";
            }
            if ($request->hasFile($fileKey)) {
                $urls[$key] = $this->storeFile($request->file($fileKey), $key);
            }
        }

        return $urls;
    }

    protected function storeFile(UploadedFile $file, String $key): String
    {
        $baseName = str_ends_with($key, '_uri') ? substr($key, 0, -4) : $key;
        $extension = $key === 'favicon_uri'
            ? 'ico'
            : ($file->getClientOriginalExtension() ?: 'png');

        $directory = "landlord/logos";
        $fileName = "{$baseName}.{$extension}";
        $path = "{$directory}/{$fileName}";

        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }

        $fileContent = file_get_contents($file->getRealPath());
        Storage::disk('public')->put($path, $fileContent);

        return Storage::disk('public')->url($path);
    }

    protected function generatePwaIconVariants(string $sourcePath, string $baseDir): array

    {
        Storage::disk('public')->makeDirectory($baseDir);

        $icon192 = "{$baseDir}/icon-192x192.png";
        $icon512 = "{$baseDir}/icon-512x512.png";
        $iconMaskable512 = "{$baseDir}/icon-maskable-512x512.png";

        // 192x192: contain + center on transparent canvas
        $tmp192 = Image::read($sourcePath)->contain(192, 192);
        $canvas192 = Image::create(192, 192);
        $canvas192->place($tmp192, 'center')
            ->save(Storage::disk('public')->path($icon192));

        // 512x512: contain + center
        $tmp512 = Image::read($sourcePath)->contain(512, 512);
        $canvas512 = Image::create(512, 512);
        $canvas512->place($tmp512, 'center')
            ->save(Storage::disk('public')->path($icon512));

        // Maskable 512x512 with safe padding (~80% content area)
        $canvas = Image::create(512, 512);
        $content = Image::read($sourcePath)->contain(410, 410);
        $canvas->place($content, 'center')
            ->save(Storage::disk('public')->path($iconMaskable512));

        return [
            'icon192_uri' => Storage::disk('public')->url($icon192),
            'icon512_uri' => Storage::disk('public')->url($icon512),
            'icon_maskable512_uri' => Storage::disk('public')->url($iconMaskable512),
        ];
    }
}
