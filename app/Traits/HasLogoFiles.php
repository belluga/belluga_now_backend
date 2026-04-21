<?php

declare(strict_types=1);

namespace App\Traits;

use App\Application\Branding\BrandingAssetDefinitionFactory;
use App\Application\Media\CanonicalImageMediaService;
use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Intervention\Image\Laravel\Facades\Image;

trait HasLogoFiles
{
    protected array $logoKeys = ['light_logo_uri', 'dark_logo_uri', 'light_icon_uri', 'dark_icon_uri', 'favicon_uri'];

    protected function processLogoUploads(Request $request, Tenant|Landlord|null $brandable): array
    {
        /** @var CanonicalImageMediaService $mediaService */
        $mediaService = app(CanonicalImageMediaService::class);
        /** @var BrandingAssetDefinitionFactory $definitionFactory */
        $definitionFactory = app(BrandingAssetDefinitionFactory::class);
        $baseUrl = $request->getSchemeAndHttpHost();
        $urls = [];

        foreach ($this->logoKeys as $key) {
            $fileKey = "branding_data.logo_settings.{$key}";
            if (! $request->hasFile($fileKey)) {
                $fileKey = "logo_settings.{$key}";
            }

            $file = $request->file($fileKey);
            if ($file instanceof UploadedFile) {
                $urls[$key] = $mediaService->storeUpload(
                    $definitionFactory->definition($brandable, $key),
                    $file,
                    $baseUrl,
                );
            }
        }

        return $urls;
    }

    protected function generatePwaIconVariants(
        UploadedFile $sourceFile,
        Tenant|Landlord|null $brandable,
        string $baseUrl,
    ): array {
        /** @var CanonicalImageMediaService $mediaService */
        $mediaService = app(CanonicalImageMediaService::class);
        /** @var BrandingAssetDefinitionFactory $definitionFactory */
        $definitionFactory = app(BrandingAssetDefinitionFactory::class);

        $sourcePath = $sourceFile->getRealPath();
        if (! is_string($sourcePath) || $sourcePath === '') {
            return [];
        }

        $sourceUri = $mediaService->storeUpload(
            $definitionFactory->definition($brandable, BrandingAssetDefinitionFactory::PWA_SOURCE_KEY),
            $sourceFile,
            $baseUrl,
        );

        $canvas192 = Image::create(192, 192)->place(Image::read($sourcePath)->contain(192, 192), 'center');
        $icon192Uri = $mediaService->storeContent(
            $definitionFactory->definition($brandable, BrandingAssetDefinitionFactory::PWA_ICON_192_KEY),
            $canvas192->toPng()->toString(),
            $baseUrl,
        );

        $canvas512 = Image::create(512, 512)->place(Image::read($sourcePath)->contain(512, 512), 'center');
        $icon512Uri = $mediaService->storeContent(
            $definitionFactory->definition($brandable, BrandingAssetDefinitionFactory::PWA_ICON_512_KEY),
            $canvas512->toPng()->toString(),
            $baseUrl,
        );

        $canvasMaskable = Image::create(512, 512)->place(Image::read($sourcePath)->contain(410, 410), 'center');
        $maskableUri = $mediaService->storeContent(
            $definitionFactory->definition($brandable, BrandingAssetDefinitionFactory::PWA_ICON_MASKABLE_512_KEY),
            $canvasMaskable->toPng()->toString(),
            $baseUrl,
        );

        return [
            BrandingAssetDefinitionFactory::PWA_SOURCE_KEY => $sourceUri,
            BrandingAssetDefinitionFactory::PWA_ICON_192_KEY => $icon192Uri,
            BrandingAssetDefinitionFactory::PWA_ICON_512_KEY => $icon512Uri,
            BrandingAssetDefinitionFactory::PWA_ICON_MASKABLE_512_KEY => $maskableUri,
        ];
    }
}
