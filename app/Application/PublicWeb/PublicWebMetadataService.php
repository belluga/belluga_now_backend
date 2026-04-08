<?php

declare(strict_types=1);

namespace App\Application\PublicWeb;

use App\Application\AccountProfiles\AccountProfileFormatterService;
use App\Application\AccountProfiles\AccountProfileQueryService;
use App\Application\Branding\BrandingManifestService;
use App\Application\StaticAssets\StaticAssetQueryService;
use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
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
    ) {}

    /**
     * @return array<string, string>
     */
    public function defaultMetadata(?string $path = null): array
    {
        $tenant = Tenant::current();
        $landlord = $tenant === null ? Landlord::singleton() : null;
        $siteName = trim((string) ($tenant?->name ?? $landlord?->name ?? config('app.name', 'Belluga Now')));
        $siteName = $siteName !== '' ? $siteName : 'Belluga Now';
        $description = trim((string) ($tenant?->description ?? ''));
        if ($description === '') {
            $description = "Descubra eventos, parceiros e lugares em {$siteName}.";
        }

        return [
            'title' => $siteName,
            'description' => $this->excerpt($description),
            'image' => $this->defaultImageUrl(),
            'canonical_url' => $this->canonicalUrlForPath($path),
            'site_name' => $siteName,
            'type' => 'website',
        ];
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

        return $metadata;
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

        return $metadata;
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

        return $metadata;
    }

    private function canonicalUrlForPath(?string $path = null): string
    {
        $base = request()->getSchemeAndHttpHost();
        $normalizedPath = trim((string) ($path ?? request()->getPathInfo() ?? '/'));
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
            if (Str::startsWith($normalized, ['http://', 'https://'])) {
                return $this->normalizePublicUrl($normalized);
            }
            if (str_starts_with($normalized, '/')) {
                return request()->getSchemeAndHttpHost().$normalized;
            }

            return request()->getSchemeAndHttpHost().'/'.$normalized;
        }

        return request()->getSchemeAndHttpHost().'/logo-dark.png';
    }

    private function normalizePublicUrl(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return $url;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return $url;
        }

        if (! $this->shouldRewriteToPublicHost($host)) {
            return $url;
        }

        $path = (string) ($parts['path'] ?? '');
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return request()->getSchemeAndHttpHost().$path.$query.$fragment;
    }

    private function shouldRewriteToPublicHost(string $host): bool
    {
        $requestHost = strtolower((string) request()->getHost());
        if ($host === $requestHost) {
            return false;
        }

        if (in_array($host, ['localhost', '127.0.0.1', 'nginx', 'app', 'laravel'], true)) {
            return true;
        }

        return ! str_contains($host, '.');
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
