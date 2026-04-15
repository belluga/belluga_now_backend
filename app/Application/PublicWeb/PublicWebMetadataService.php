<?php

declare(strict_types=1);

namespace App\Application\PublicWeb;

use App\Application\AccountProfiles\AccountProfileFormatterService;
use App\Application\AccountProfiles\AccountProfileQueryService;
use App\Application\Branding\BrandingPublicWebMediaService;
use App\Application\Branding\BrandingManifestService;
use App\Application\StaticAssets\StaticAssetQueryService;
use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
use App\Support\Helpers\ArrayReplaceEmptyAware;
use Belluga\Events\Application\Events\EventQueryService;
use Belluga\Events\Exceptions\EventNotPubliclyVisibleException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

class PublicWebMetadataService
{
    public function __construct(
        private readonly BrandingManifestService $brandingManifestService,
        private readonly AccountProfileQueryService $accountProfileQueryService,
        private readonly AccountProfileFormatterService $accountProfileFormatterService,
        private readonly EventQueryService $eventQueryService,
        private readonly StaticAssetQueryService $staticAssetQueryService,
        private readonly BrandingPublicWebMediaService $brandingPublicWebMediaService,
    ) {}

    /**
     * @return array<string, string>
     */
    public function defaultMetadata(?string $path = null): array
    {
        $tenant = $this->currentTenant();
        $landlord = $tenant === null ? $this->currentLandlord() : null;
        $branding = $this->resolveCurrentBrandingData();
        $siteName = trim((string) ($tenant?->name ?? $landlord?->name ?? config('app.name', 'Belluga Now')));
        $siteName = $siteName !== '' ? $siteName : 'Belluga Now';

        $title = trim((string) data_get($branding, 'public_web_metadata.default_title', ''));
        if ($title === '') {
            $title = $siteName;
        }

        $description = trim((string) data_get($branding, 'public_web_metadata.default_description', ''));
        if ($description === '') {
            $description = trim((string) ($tenant?->description ?? ''));
        }
        if ($description === '') {
            $description = "Descubra eventos, parceiros e lugares em {$siteName}.";
        }

        $metadata = [
            'title' => $title,
            'description' => $this->excerpt($description),
            'image' => $this->resolveImageUrl([
                $this->resolveBrandingFallbackImage($tenant, $landlord),
                $this->defaultImageUrl(),
            ]),
            'canonical_url' => $this->canonicalUrlForPath($path),
            'site_name' => $siteName,
            'type' => 'website',
        ];

        return $this->enrichImageMetadata($metadata, $tenant, $landlord);
    }

    /**
     * @return array<string, string>
     */
    public function accountProfileMetadata(string $slug): array
    {
        $metadata = $this->defaultMetadata('/parceiro/'.$slug);

        try {
            $profile = $this->accountProfileQueryService->publicFindBySlugOrFail($slug);
            $payload = $this->accountProfileFormatterService->format($profile);
        } catch (ModelNotFoundException) {
            return $metadata;
        }

        $displayName = trim((string) ($payload['display_name'] ?? ''));
        if ($displayName !== '') {
            $metadata['title'] = "{$displayName} | {$metadata['site_name']}";
        }

        $metadata['description'] = $this->excerpt(
            $this->sanitizeText((string) ($payload['content'] ?? ''))
            ?: $this->sanitizeText((string) ($payload['bio'] ?? ''))
            ?: $metadata['description']
        );
        $metadata['image'] = $this->resolveImageUrl([
            $payload['cover_url'] ?? null,
            $payload['avatar_url'] ?? null,
            $metadata['image'],
        ]);
        $metadata['canonical_url'] = $this->canonicalUrlForPath('/parceiro/'.trim((string) ($payload['slug'] ?? $slug)));
        $metadata['type'] = 'profile';

        return $this->enrichImageMetadata($metadata, $this->currentTenant(), null);
    }

    /**
     * @return array<string, string>
     */
    public function eventMetadata(string $slug): array
    {
        $metadata = $this->defaultMetadata('/agenda/evento/'.$slug);

        try {
            $event = $this->eventQueryService->findByIdOrSlug($slug);
            if ($event === null) {
                return $metadata;
            }

            $this->eventQueryService->assertPublicVisible($event);
            $payload = $this->eventQueryService->formatEvent($event);
        } catch (ModelNotFoundException|EventNotPubliclyVisibleException) {
            return $metadata;
        }

        $title = trim((string) ($payload['title'] ?? ''));
        if ($title !== '') {
            $metadata['title'] = "{$title} | {$metadata['site_name']}";
        }

        $metadata['description'] = $this->excerpt(
            $this->sanitizeText((string) ($payload['content'] ?? ''))
            ?: $this->eventFallbackDescription($payload)
            ?: $metadata['description']
        );
        $metadata['image'] = $this->resolveImageUrl([
            data_get($payload, 'thumb.data.url'),
            $this->firstProfileImage($payload['artists'] ?? []),
            $this->firstProfileImage($payload['linked_account_profiles'] ?? []),
            data_get($payload, 'venue.cover_url'),
            data_get($payload, 'venue.avatar_url'),
            $metadata['image'],
        ]);
        $metadata['canonical_url'] = $this->canonicalUrlForPath('/agenda/evento/'.trim((string) ($payload['slug'] ?? $slug)));
        $metadata['type'] = 'article';

        return $this->enrichImageMetadata($metadata, $this->currentTenant(), null);
    }

    /**
     * @return array<string, string>
     */
    public function staticAssetMetadata(string $assetRef): array
    {
        $metadata = $this->defaultMetadata('/static/'.$assetRef);

        try {
            $asset = $this->staticAssetQueryService->findByIdOrSlug($assetRef);
            $payload = $this->staticAssetQueryService->format($asset);
        } catch (ModelNotFoundException) {
            return $metadata;
        }

        $displayName = trim((string) ($payload['display_name'] ?? ''));
        if ($displayName !== '') {
            $metadata['title'] = "{$displayName} | {$metadata['site_name']}";
        }

        $metadata['description'] = $this->excerpt(
            $this->sanitizeText((string) ($payload['content'] ?? ''))
            ?: $this->sanitizeText((string) ($payload['bio'] ?? ''))
            ?: $metadata['description']
        );
        $metadata['image'] = $this->resolveImageUrl([
            $payload['cover_url'] ?? null,
            $metadata['image'],
        ]);
        $metadata['canonical_url'] = $this->canonicalUrlForPath('/static/'.trim((string) ($payload['slug'] ?? $assetRef)));
        $metadata['type'] = 'place';

        return $this->enrichImageMetadata($metadata, $this->currentTenant(), null);
    }

    private function canonicalUrlForPath(?string $path = null): string
    {
        $base = request()->getSchemeAndHttpHost();
        $normalizedPath = trim((string) ($path ?? request()->getRequestUri() ?? '/'));
        if ($normalizedPath === '') {
            $normalizedPath = '/';
        }
        if (! str_starts_with($normalizedPath, '/')) {
            $normalizedPath = '/'.$normalizedPath;
        }

        return $base.$normalizedPath;
    }

    private function defaultImageUrl(): string
    {
        return $this->resolveImageUrl([
            $this->brandingManifestService->resolveLogoSetting('dark_logo_uri'),
            $this->brandingManifestService->resolveLogoSetting('light_logo_uri'),
            $this->brandingManifestService->resolvePwaIcon('icon512_uri'),
            '/logo-dark.png',
        ]);
    }

    /**
     * @param  array<int, mixed>  $candidates
     */
    private function resolveImageUrl(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $normalized = trim((string) $candidate);
            if ($normalized === '') {
                continue;
            }
            if (str_starts_with($normalized, 'http://') || str_starts_with($normalized, 'https://')) {
                return $normalized;
            }
            if (str_starts_with($normalized, '/')) {
                return request()->getSchemeAndHttpHost().$normalized;
            }

            return request()->getSchemeAndHttpHost().'/'.$normalized;
        }

        return request()->getSchemeAndHttpHost().'/logo-dark.png';
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveCurrentBrandingData(): array
    {
        $landlordBranding = $this->normalizeBrandingData($this->currentLandlord()->branding_data ?? null);
        $tenantBranding = $this->normalizeBrandingData($this->currentTenant()?->branding_data ?? null);

        return ArrayReplaceEmptyAware::mergeIfOverridenIsNotEmptyRecursive(
            mainArray: $landlordBranding,
            overrideArray: $tenantBranding
        );
    }

    private function currentTenant(): ?Tenant
    {
        $tenant = Tenant::current();

        if ($tenant === null) {
            return null;
        }

        return $tenant->fresh() ?? $tenant;
    }

    private function currentLandlord(): Landlord
    {
        $landlord = Landlord::singleton();

        return $landlord->fresh() ?? $landlord;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeBrandingData(mixed $branding): array
    {
        if (is_array($branding)) {
            return $branding;
        }

        if ($branding instanceof \Traversable) {
            return iterator_to_array($branding);
        }

        if (is_object($branding) && method_exists($branding, 'toArray')) {
            $normalized = $branding->toArray();

            return is_array($normalized) ? $normalized : [];
        }

        return [];
    }

    private function resolveBrandingFallbackImage(?Tenant $tenant, ?Landlord $landlord): ?string
    {
        $baseUrl = request()->getSchemeAndHttpHost();

        if ($tenant !== null) {
            $tenantImage = trim((string) data_get(
                $this->normalizeBrandingData($tenant->branding_data ?? null),
                'public_web_metadata.default_image',
                '',
            ));

            if ($tenantImage !== '') {
                return $this->brandingPublicWebMediaService->normalizePublicUrl(
                    $baseUrl,
                    $tenant,
                    $tenantImage,
                );
            }
        }

        $resolvedLandlord = $landlord ?? $this->currentLandlord();
        $landlordImage = trim((string) data_get(
            $this->normalizeBrandingData($resolvedLandlord->branding_data ?? null),
            'public_web_metadata.default_image',
            '',
        ));

        if ($landlordImage === '') {
            return null;
        }

        return $this->brandingPublicWebMediaService->normalizePublicUrl(
            $baseUrl,
            $resolvedLandlord,
            $landlordImage,
        );
    }

    private function sanitizeText(string $value): string
    {
        $stripped = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $stripped));
    }

    private function excerpt(string $value): string
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return '';
        }

        return Str::limit($normalized, 180, '...');
    }

    /**
     * @param  array<string, string>  $metadata
     * @return array<string, string>
     */
    private function enrichImageMetadata(
        array $metadata,
        ?Tenant $tenant,
        ?Landlord $landlord,
    ): array {
        $imageUrl = trim((string) ($metadata['image'] ?? ''));
        $title = trim((string) ($metadata['title'] ?? $metadata['site_name'] ?? ''));

        $metadata['image_secure_url'] = str_starts_with($imageUrl, 'https://')
            ? $imageUrl
            : '';
        $metadata['image_type'] = $this->inferImageMimeType($imageUrl);
        $metadata['image_width'] = '';
        $metadata['image_height'] = '';
        $metadata['image_alt'] = $title;

        $properties = $this->resolveBrandingImagePropertiesForSelectedImage(
            $tenant,
            $landlord,
            $imageUrl,
        );

        if ($properties !== []) {
            if ($metadata['image_type'] === '' && trim((string) ($properties['type'] ?? '')) !== '') {
                $metadata['image_type'] = trim((string) $properties['type']);
            }
            $metadata['image_width'] = trim((string) ($properties['width'] ?? ''));
            $metadata['image_height'] = trim((string) ($properties['height'] ?? ''));
        }

        return $metadata;
    }

    /**
     * @return array{width:string,height:string,type:string}|array{}
     */
    private function resolveBrandingImagePropertiesForSelectedImage(
        ?Tenant $tenant,
        ?Landlord $landlord,
        string $imageUrl,
    ): array {
        if ($imageUrl === '') {
            return [];
        }

        $baseUrl = request()->getSchemeAndHttpHost();

        if ($tenant !== null) {
            $tenantBrandingImage = $this->resolveBrandablePublicWebImageUrl($tenant, $baseUrl);
            if ($tenantBrandingImage !== null && $tenantBrandingImage === $imageUrl) {
                return $this->brandingPublicWebMediaService->resolveImagePropertiesForBaseUrl(
                    $tenant,
                    $baseUrl,
                );
            }
        }

        $resolvedLandlord = $landlord ?? $this->currentLandlord();
        $landlordBrandingImage = $this->resolveBrandablePublicWebImageUrl(
            $resolvedLandlord,
            $baseUrl,
        );

        if ($landlordBrandingImage !== null && $landlordBrandingImage === $imageUrl) {
            return $this->brandingPublicWebMediaService->resolveImagePropertiesForBaseUrl(
                $resolvedLandlord,
                $baseUrl,
            );
        }

        return [];
    }

    private function resolveBrandablePublicWebImageUrl(
        Tenant|Landlord $brandable,
        string $baseUrl,
    ): ?string {
        $rawImage = trim((string) data_get(
            $this->normalizeBrandingData($brandable->branding_data ?? null),
            'public_web_metadata.default_image',
            '',
        ));

        if ($rawImage === '') {
            return null;
        }

        return $this->brandingPublicWebMediaService->normalizePublicUrl(
            $baseUrl,
            $brandable,
            $rawImage,
        );
    }

    private function inferImageMimeType(string $imageUrl): string
    {
        if ($imageUrl === '') {
            return '';
        }

        $path = parse_url($imageUrl, PHP_URL_PATH);
        if (! is_string($path) || trim($path) === '') {
            return '';
        }

        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'ico' => 'image/vnd.microsoft.icon',
            default => '',
        };
    }

    /**
     * @param  array<int, mixed>  $profiles
     */
    private function firstProfileImage(array $profiles): ?string
    {
        foreach ($profiles as $profile) {
            if (! is_array($profile)) {
                continue;
            }

            $cover = trim((string) ($profile['cover_url'] ?? ''));
            if ($cover !== '') {
                return $cover;
            }

            $avatar = trim((string) ($profile['avatar_url'] ?? ''));
            if ($avatar !== '') {
                return $avatar;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function eventFallbackDescription(array $payload): string
    {
        $venue = trim((string) data_get($payload, 'venue.display_name', ''));
        $place = trim((string) data_get($payload, 'place_ref.display_name', ''));
        $location = trim((string) data_get($payload, 'location.display_name', ''));
        $eventTitle = trim((string) ($payload['title'] ?? ''));

        foreach ([$venue, $place, $location] as $label) {
            if ($label !== '') {
                return $eventTitle !== ''
                    ? "Confira {$eventTitle} em {$label}."
                    : "Confira os detalhes deste evento em {$label}.";
            }
        }

        return '';
    }
}
