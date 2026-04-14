<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controllers;

use App\Application\Branding\BrandingManifestService;
use App\Http\Api\v1\Controllers\BrandingController;
use Belluga\DeepLinks\Application\DeepLinkAssociationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BrandingControllerTest extends TestCase
{
    public function test_get_favicon_applies_no_store_headers(): void
    {
        $brandingService = $this->createMock(BrandingManifestService::class);
        $deepLinkAssociationService = $this->createMock(DeepLinkAssociationService::class);

        $brandingService->expects($this->once())
            ->method('resolveFaviconAsset')
            ->willReturn('/storage/tenants/test/pwa/icon-192x192.png');

        $filePath = tempnam(sys_get_temp_dir(), 'branding-favicon-');
        File::put($filePath, 'png-fallback');

        $brandingService->expects($this->once())
            ->method('assetResponse')
            ->with('/storage/tenants/test/pwa/icon-192x192.png')
            ->willReturn(response()->file($filePath, [
                'Content-Type' => 'image/png',
            ]));

        try {
            $controller = new BrandingController(
                $brandingService,
                $deepLinkAssociationService
            );

            $response = $controller->getFavicon();

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('image/png', $response->headers->get('Content-Type'));
            $this->assertSame('no-cache', $response->headers->get('Pragma'));
            $this->assertSame('0', $response->headers->get('Expires'));

            $cacheControl = (string) $response->headers->get('Cache-Control');
            $this->assertStringContainsString('no-store', $cacheControl);
            $this->assertStringContainsString('no-cache', $cacheControl);
            $this->assertStringContainsString('must-revalidate', $cacheControl);
            $this->assertStringContainsString('max-age=0', $cacheControl);
        } finally {
            if (is_string($filePath) && $filePath !== '' && File::exists($filePath)) {
                File::delete($filePath);
            }
        }
    }

    public function test_get_manifest_applies_no_store_headers(): void
    {
        $brandingService = $this->createMock(BrandingManifestService::class);
        $deepLinkAssociationService = $this->createMock(DeepLinkAssociationService::class);

        $brandingService->expects($this->once())
            ->method('buildManifest')
            ->with('tenant-sigma.test')
            ->willReturn([
                'name' => 'Tenant Sigma',
                'icons' => [],
            ]);

        $controller = new BrandingController(
            $brandingService,
            $deepLinkAssociationService
        );

        $request = Request::create('https://tenant-sigma.test/manifest.json', 'GET');
        $response = $controller->getManifest($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/manifest+json', $response->headers->get('Content-Type'));
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
        $this->assertSame('0', $response->headers->get('Expires'));

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringContainsString('must-revalidate', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);
    }
}
