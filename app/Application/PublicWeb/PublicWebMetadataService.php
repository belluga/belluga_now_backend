<?php

declare(strict_types=1);

namespace App\Application\PublicWeb;

use App\Application\AccountProfiles\AccountProfileFormatterService;
use App\Application\AccountProfiles\AccountProfileHeroImageResolver;
use App\Application\AccountProfiles\AccountProfileQueryService;
use App\Application\Branding\BrandingPublicWebMediaService;
use App\Application\StaticAssets\StaticAssetQueryService;
use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
use App\Support\Helpers\ArrayReplaceEmptyAware;
use Belluga\Events\Application\Events\EventHeroImageResolver;
use Belluga\Events\Application\Events\EventQueryService;
use Belluga\Events\Exceptions\EventNotPubliclyVisibleException;
use Belluga\Invites\Application\Mutations\InviteShareService;
use Belluga\Invites\Support\InviteDomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

class PublicWebMetadataService
{
    public function __construct(
        private readonly AccountProfileQueryService $accountProfileQueryService,
        private readonly AccountProfileFormatterService $accountProfileFormatterService,
        private readonly AccountProfileHeroImageResolver $accountProfileHeroImages,
        private readonly EventQueryService $eventQueryService,
        private readonly EventHeroImageResolver $eventHeroImages,
        private readonly StaticAssetQueryService $staticAssetQueryService,
        private readonly BrandingPublicWebMediaService $brandingPublicWebMediaService,
        private readonly InviteShareService $inviteShareService,
    ) {}

    /**
     * @return array<string, string>
     */
    public function defaultMetadata(?string $path = null): array
    {
        return $this->defaultMetadataForContext($path, $this->resolveRequestContext());
    }

    /**
     * @return array<string, string>
     */
    public function accountProfileMetadata(string $slug): array
    {
        $context = $this->resolveRequestContext();
        $metadata = $this->defaultMetadataForContext('/parceiro/'.$slug, $context);

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
            $this->accountProfileHeroImages->resolveFromPayload(
                $payload,
                allowTypeVisualFallback: true
            ),
            $metadata['image'],
        ]);
        $metadata['canonical_url'] = $this->canonicalUrlForPath('/parceiro/'.trim((string) ($payload['slug'] ?? $slug)));
        $metadata['type'] = 'profile';

        return $this->enrichImageMetadataForContext($metadata, $context);
    }

    /**
     * @return array<string, string>
     */
    public function eventMetadata(string $slug): array
    {
        $context = $this->resolveRequestContext();
        $metadata = $this->defaultMetadataForContext('/agenda/evento/'.$slug, $context);

        try {
            $event = $this->eventQueryService->findByIdOrSlug($slug);
            if ($event === null) {
                return $metadata;
            }

            $this->eventQueryService->assertPublicVisible($event);
            $payload = $this->eventQueryService->formatMetadataEvent($event);
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
            $this->eventHeroImages->resolveFromPayload($payload),
            $metadata['image'],
        ]);
        $metadata['canonical_url'] = $this->canonicalUrlForPath('/agenda/evento/'.trim((string) ($payload['slug'] ?? $slug)));
        $metadata['type'] = 'article';

        return $this->enrichImageMetadataForContext($metadata, $context);
    }

    /**
     * @return array<string, string>
     */
    public function staticAssetMetadata(string $assetRef): array
    {
        $context = $this->resolveRequestContext();
        $metadata = $this->defaultMetadataForContext('/static/'.$assetRef, $context);

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

        return $this->enrichImageMetadataForContext($metadata, $context);
    }

    /**
     * @return array<string, string>
     */
    public function inviteMetadata(?string $shareCode): array
    {
        $normalizedCode = strtoupper(trim((string) $shareCode));
        $path = '/invite';
        if ($normalizedCode !== '') {
            $path .= '?code='.rawurlencode($normalizedCode);
        }
        $context = $this->resolveRequestContext();
        $metadata = $this->defaultMetadataForContext($path, $context);

        if ($normalizedCode === '') {
            return $metadata;
        }

        try {
            $preview = $this->inviteShareService->preview($normalizedCode);
        } catch (InviteDomainException) {
            return $metadata;
        }

        $invite = is_array($preview['invite'] ?? null)
            ? $preview['invite']
            : [];
        $eventName = trim((string) ($invite['event_name'] ?? ''));
        if ($eventName !== '') {
            $metadata['title'] = "{$eventName} | {$metadata['site_name']}";
        }

        $inviterDisplayName = trim((string) data_get($invite, 'inviter_candidates.0.display_name', ''));
        $location = trim((string) ($invite['location'] ?? ''));
        $metadata['description'] = $this->excerpt(
            $this->inviteFallbackDescription(
                inviterDisplayName: $inviterDisplayName,
                eventName: $eventName,
                location: $location,
            ) ?: $metadata['description']
        );
        $metadata['image'] = $this->resolveImageUrl([
            $invite['hero_image_url'] ?? null,
            $metadata['image'],
        ]);
        $metadata['canonical_url'] = $this->canonicalUrlForPath($path);
        $metadata['type'] = 'article';

        return $this->enrichImageMetadataForContext($metadata, $context);
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

    private function inviteFallbackDescription(
        string $inviterDisplayName,
        string $eventName,
        string $location,
    ): ?string {
        if ($inviterDisplayName !== '' && $eventName !== '' && $location !== '') {
            return "{$inviterDisplayName} convidou você para {$eventName} em {$location}.";
        }

        if ($eventName !== '' && $location !== '') {
            return "Convite para {$eventName} em {$location}.";
        }

        if ($eventName !== '') {
            return "Convite para {$eventName}.";
        }

        return null;
    }

    /**
     * @param  array{
     *   tenant: ?Tenant,
     *   landlord: Landlord,
     *   branding: array<string, mixed>,
     *   base_url: string,
     *   tenant_branding_image_url: ?string,
     *   landlord_branding_image_url: ?string,
     *   branding_fallback_image_url: ?string
     * }  $context
     * @return array<string, string>
     */
    private function defaultMetadataForContext(?string $path, array $context): array
    {
        $tenant = $context['tenant'];
        $landlord = $context['landlord'];
        $branding = $context['branding'];
        $siteName = trim((string) ($tenant?->name ?? $landlord->name ?? config('app.name', 'Belluga Now')));
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
                $context['branding_fallback_image_url'],
                $this->defaultImageUrlForContext($context),
            ]),
            'canonical_url' => $this->canonicalUrlForPath($path),
            'site_name' => $siteName,
            'type' => 'website',
        ];

        return $this->enrichImageMetadataForContext($metadata, $context);
    }

    /**
     * @return array{
     *   tenant: ?Tenant,
     *   landlord: Landlord,
     *   branding: array<string, mixed>,
     *   base_url: string,
     *   tenant_branding_image_url: ?string,
     *   landlord_branding_image_url: ?string,
     *   branding_fallback_image_url: ?string
     * }
     */
    private function resolveRequestContext(): array
    {
        $tenant = Tenant::current();
        $landlord = $this->currentLandlord();
        $baseUrl = request()->getSchemeAndHttpHost();
        $tenantBranding = $this->normalizeBrandingData($tenant?->branding_data ?? null);
        $landlordBranding = $this->normalizeBrandingData($landlord->branding_data ?? null);
        $branding = ArrayReplaceEmptyAware::mergeIfOverridenIsNotEmptyRecursive(
            mainArray: $landlordBranding,
            overrideArray: $tenantBranding
        );
        $tenantBrandingImageUrl = $tenant instanceof Tenant
            ? $this->resolveBrandablePublicWebImageUrl($tenant, $tenantBranding, $baseUrl)
            : null;
        $landlordBrandingImageUrl = $this->resolveBrandablePublicWebImageUrl(
            $landlord,
            $landlordBranding,
            $baseUrl,
        );

        return [
            'tenant' => $tenant,
            'landlord' => $landlord,
            'branding' => $branding,
            'base_url' => $baseUrl,
            'tenant_branding_image_url' => $tenantBrandingImageUrl,
            'landlord_branding_image_url' => $landlordBrandingImageUrl,
            'branding_fallback_image_url' => $tenantBrandingImageUrl ?? $landlordBrandingImageUrl,
        ];
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

    /**
     * @param  array{
     *   branding: array<string, mixed>
     * }  $context
     */
    private function defaultImageUrlForContext(array $context): string
    {
        $branding = $context['branding'];

        return $this->resolveImageUrl([
            data_get($branding, 'logo_settings.dark_logo_uri'),
            data_get($branding, 'logo_settings.light_logo_uri'),
            data_get($branding, 'pwa_icon.icon512_uri'),
            '/logo-dark.png',
        ]);
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
    private function enrichImageMetadataForContext(
        array $metadata,
        array $context,
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

        $properties = $this->resolveBrandingImagePropertiesForSelectedImage($context, $imageUrl);

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
     * @param  array{
     *   tenant: ?Tenant,
     *   landlord: Landlord,
     *   base_url: string,
     *   tenant_branding_image_url: ?string,
     *   landlord_branding_image_url: ?string
     * }  $context
     * @return array{width:string,height:string,type:string}|array{}
     */
    private function resolveBrandingImagePropertiesForSelectedImage(
        array $context,
        string $imageUrl,
    ): array {
        if ($imageUrl === '') {
            return [];
        }

        $baseUrl = $context['base_url'];
        $tenant = $context['tenant'];

        if ($tenant instanceof Tenant && $context['tenant_branding_image_url'] === $imageUrl) {
            return $this->brandingPublicWebMediaService->resolveImagePropertiesForBaseUrl(
                $tenant,
                $baseUrl,
            );
        }

        if ($context['landlord_branding_image_url'] === $imageUrl) {
            return $this->brandingPublicWebMediaService->resolveImagePropertiesForBaseUrl(
                $context['landlord'],
                $baseUrl,
            );
        }

        return [];
    }

    private function resolveBrandablePublicWebImageUrl(
        Tenant|Landlord $brandable,
        array $branding,
        string $baseUrl,
    ): ?string {
        $rawImage = trim((string) data_get($branding, 'public_web_metadata.default_image', ''));

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
