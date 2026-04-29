<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Application\Shared\MapPois\MapPoiProjectionRefService;
use App\Application\Shared\MapPois\PoiVisualNormalizer;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Belluga\MapPois\Jobs\DeleteMapPoiByRefJob;
use Belluga\MapPois\Jobs\UpsertMapPoiFromAccountProfileJob;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MongoDB\Driver\Exception\BulkWriteException;

class AccountProfileRegistryManagementService
{
    public function __construct(
        private readonly PoiVisualNormalizer $poiVisualNormalizer,
        private readonly MapPoiProjectionRefService $mapPoiProjectionRefs,
        private readonly AccountProfileTypeMediaService $mediaService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function create(Request $request, array $payload): array
    {
        $type = trim((string) ($payload['type'] ?? ''));
        if (TenantProfileType::query()->where('type', $type)->exists()) {
            throw ValidationException::withMessages([
                'type' => ['Profile type already exists.'],
            ]);
        }

        $entry = $this->buildEntry($payload, $type);
        $this->ensureTypeAssetRequirements(
            $entry['visual'] ?? null,
            $request,
            null,
            false,
        );

        $model = TenantProfileType::create($entry);
        $this->mediaService->applyUploads($request, $model);
        $model = $model->fresh() ?? $model;

        return $this->toPayload($model, $request->getSchemeAndHttpHost());
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(Request $request, string $type, array $payload): array
    {
        $type = trim($type);
        $model = TenantProfileType::query()->where('type', $type)->first();
        if (! $model) {
            abort(404, 'Profile type not found.');
        }

        $nextType = array_key_exists('type', $payload)
            ? trim((string) $payload['type'])
            : (string) ($model->type ?? '');
        $currentType = (string) ($model->type ?? '');

        if ($nextType !== $currentType) {
            if (TenantProfileType::query()->where('type', $nextType)->exists()) {
                throw ValidationException::withMessages([
                    'type' => ['Profile type already exists.'],
                ]);
            }
        }

        $entry = $this->mergeEntry($model, $payload, $nextType);
        $currentCapabilities = is_array($model->capabilities ?? null)
            ? $model->capabilities
            : [];
        $currentPoiEnabled = (bool) ($currentCapabilities['is_poi_enabled'] ?? false);
        $nextCapabilities = is_array($entry['capabilities'] ?? null)
            ? $entry['capabilities']
            : [];
        $nextPoiEnabled = (bool) ($nextCapabilities['is_poi_enabled'] ?? false);
        $currentPoiVisual = $this->poiVisualNormalizer->normalize($model->visual ?? $model->poi_visual ?? null);
        $nextPoiVisual = $this->poiVisualNormalizer->normalize($entry['visual'] ?? $entry['poi_visual'] ?? null);
        $poiVisualChanged = $currentPoiVisual !== $nextPoiVisual;
        $currentTypeAssetUrl = $this->normalizeTypeAssetUrl($model->type_asset_url ?? null);

        $this->ensureTypeAssetRequirements(
            $nextPoiVisual,
            $request,
            $currentTypeAssetUrl,
            $request->boolean('remove_type_asset'),
        );

        try {
            $model->fill($entry);
            $model->save();
        } catch (BulkWriteException $exception) {
            if (str_contains($exception->getMessage(), 'E11000')) {
                throw ValidationException::withMessages([
                    'type' => ['Profile type already exists.'],
                ]);
            }

            throw ValidationException::withMessages([
                'profile_type' => ['Something went wrong when trying to update the profile type.'],
            ]);
        }

        $this->mediaService->applyUploads($request, $model);
        $model = $model->fresh() ?? $model;
        $nextTypeAssetUrl = $this->normalizeTypeAssetUrl($model->type_asset_url ?? null);
        $typeAssetChanged = $currentTypeAssetUrl !== $nextTypeAssetUrl;
        $forcedCheckpoint = $this->toCheckpoint($model->updated_at ?? null);
        $shouldRefreshMapProjection = $nextType !== $currentType
            || $currentPoiEnabled !== $nextPoiEnabled
            || $poiVisualChanged
            || $typeAssetChanged;

        if ($shouldRefreshMapProjection) {
            $queryType = $nextType === $currentType ? $nextType : $currentType;
            $profileIds = AccountProfile::query()
                ->where('profile_type', $queryType)
                ->get(['_id'])
                ->map(static fn (AccountProfile $profile): string => (string) $profile->getKey())
                ->all();

            if ($nextType !== $currentType && $profileIds !== []) {
                AccountProfile::query()
                    ->where('profile_type', $currentType)
                    ->update(['profile_type' => $nextType]);
            }

            if ($profileIds !== []) {
                if (! $nextPoiEnabled) {
                    $this->mapPoiProjectionRefs->dispatchForEachRefId(
                        $profileIds,
                        static function (string $profileId): void {
                            DeleteMapPoiByRefJob::dispatch('account_profile', $profileId);
                        },
                    );
                } else {
                    $checkpoint = $forcedCheckpoint > 0 ? $forcedCheckpoint : null;
                    $this->mapPoiProjectionRefs->dispatchForEachRefId(
                        $profileIds,
                        static function (string $profileId) use ($checkpoint): void {
                            UpsertMapPoiFromAccountProfileJob::dispatch($profileId, $checkpoint);
                        },
                    );
                }
            }
        }

        return $this->toPayload($model, $request->getSchemeAndHttpHost());
    }

    public function previewDisableProjectionCount(string $type): int
    {
        $normalizedType = trim($type);
        if ($normalizedType === '') {
            return 0;
        }

        $profileIds = AccountProfile::query()
            ->where('profile_type', $normalizedType)
            ->get(['_id'])
            ->map(static fn (AccountProfile $profile): string => (string) $profile->getKey())
            ->all();

        return $this->mapPoiProjectionRefs->countByRefType('account_profile', $profileIds);
    }

    public function delete(string $type): void
    {
        $type = trim($type);
        $model = TenantProfileType::query()->where('type', $type)->first();
        if (! $model) {
            abort(404, 'Profile type not found.');
        }

        $model->delete();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function buildEntry(array $payload, string $type): array
    {
        $capabilities = $payload['capabilities'] ?? [];
        $visual = $this->resolveIncomingVisual($payload);
        $labels = $this->normalizeLabels($payload);

        return [
            'type' => $type,
            'label' => $labels['singular'],
            'labels' => $labels,
            'allowed_taxonomies' => $this->normalizeTaxonomies($payload['allowed_taxonomies'] ?? []),
            'visual' => $visual,
            'poi_visual' => $visual,
            'capabilities' => $this->normalizeCapabilities($capabilities),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function mergeEntry(TenantProfileType $existing, array $payload, string $resolvedType): array
    {
        $capabilities = $payload['capabilities'] ?? [];
        $currentCapabilities = $existing->capabilities ?? [];
        $visual = $this->resolveIncomingVisual($payload, $existing->visual ?? $existing->poi_visual ?? null);
        $labels = $this->normalizeLabels($payload, $existing);

        return [
            'type' => $resolvedType,
            'label' => $labels['singular'],
            'labels' => $labels,
            'allowed_taxonomies' => array_key_exists('allowed_taxonomies', $payload)
                ? $this->normalizeTaxonomies($payload['allowed_taxonomies'] ?? [])
                : $this->normalizeTaxonomies($existing->allowed_taxonomies ?? []),
            'visual' => $visual,
            'poi_visual' => $visual,
            'capabilities' => $this->normalizeCapabilities($capabilities, is_array($currentCapabilities) ? $currentCapabilities : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $capabilities
     * @param  array<string, mixed>  $currentCapabilities
     * @return array<string, bool>
     */
    private function normalizeCapabilities(array $capabilities, array $currentCapabilities = []): array
    {
        $isPoiEnabled = array_key_exists('is_poi_enabled', $capabilities)
            ? (bool) $capabilities['is_poi_enabled']
            : (bool) ($currentCapabilities['is_poi_enabled'] ?? false);
        $isReferenceLocationRequested = array_key_exists('is_reference_location_enabled', $capabilities)
            ? (bool) $capabilities['is_reference_location_enabled']
            : (bool) ($currentCapabilities['is_reference_location_enabled'] ?? false);

        return [
            'is_favoritable' => array_key_exists('is_favoritable', $capabilities)
                ? (bool) $capabilities['is_favoritable']
                : (bool) ($currentCapabilities['is_favoritable'] ?? false),
            'is_inviteable' => array_key_exists('is_inviteable', $capabilities)
                ? (bool) $capabilities['is_inviteable']
                : (bool) ($currentCapabilities['is_inviteable'] ?? false),
            'is_poi_enabled' => $isPoiEnabled,
            'is_reference_location_enabled' => $isPoiEnabled && $isReferenceLocationRequested,
            'has_bio' => array_key_exists('has_bio', $capabilities)
                ? (bool) $capabilities['has_bio']
                : (bool) ($currentCapabilities['has_bio'] ?? false),
            'has_content' => array_key_exists('has_content', $capabilities)
                ? (bool) $capabilities['has_content']
                : (bool) ($currentCapabilities['has_content'] ?? false),
            'has_taxonomies' => array_key_exists('has_taxonomies', $capabilities)
                ? (bool) $capabilities['has_taxonomies']
                : (bool) ($currentCapabilities['has_taxonomies'] ?? false),
            'has_avatar' => array_key_exists('has_avatar', $capabilities)
                ? (bool) $capabilities['has_avatar']
                : (bool) ($currentCapabilities['has_avatar'] ?? false),
            'has_cover' => array_key_exists('has_cover', $capabilities)
                ? (bool) $capabilities['has_cover']
                : (bool) ($currentCapabilities['has_cover'] ?? false),
            'has_events' => array_key_exists('has_events', $capabilities)
                ? (bool) $capabilities['has_events']
                : (bool) ($currentCapabilities['has_events'] ?? false),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function normalizeTaxonomies(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $normalized = array_map(static fn ($value): string => trim((string) $value), $raw);

        return array_values(array_filter(array_unique($normalized), static fn (string $value): bool => $value !== ''));
    }

    private function toCheckpoint(mixed $value): int
    {
        if ($value instanceof Carbon) {
            return (int) $value->valueOf();
        }

        if ($value instanceof \DateTimeInterface) {
            return (int) Carbon::instance($value)->valueOf();
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return (int) Carbon::parse($value)->valueOf();
            } catch (\Exception) {
                return 0;
            }
        }

        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function toPayload(TenantProfileType $model, ?string $baseUrl = null): array
    {
        $visual = $this->resolvePayloadVisual($model, $baseUrl);
        $labels = $this->normalizeLabels([], $model);
        $capabilities = is_array($model->capabilities ?? null) ? $model->capabilities : [];

        return [
            'type' => (string) $model->type,
            'label' => $labels['singular'],
            'labels' => $labels,
            'allowed_taxonomies' => array_values(array_filter(
                is_array($model->allowed_taxonomies ?? null)
                    ? $model->allowed_taxonomies
                    : [],
                static fn ($value): bool => is_string($value) && $value !== ''
            )),
            'visual' => $visual,
            'poi_visual' => $visual,
            'capabilities' => $this->normalizeCapabilities($capabilities, $capabilities),
        ];
    }

    /**
     * @return array<string, string>|null
     */
    private function resolveIncomingVisual(array $payload, mixed $fallback = null): ?array
    {
        if (array_key_exists('visual', $payload)) {
            return $this->poiVisualNormalizer->normalize($payload['visual'] ?? null);
        }

        if (array_key_exists('poi_visual', $payload)) {
            return $this->poiVisualNormalizer->normalize($payload['poi_visual'] ?? null);
        }

        return $this->poiVisualNormalizer->normalize($fallback);
    }

    /**
     * @return array{singular: string, plural: string}
     */
    private function normalizeLabels(array $payload, ?TenantProfileType $existing = null): array
    {
        $existingLabels = is_array($existing?->labels ?? null)
            ? $existing->labels
            : [];
        $existingSingular = trim((string) ($existingLabels['singular'] ?? $existing?->label ?? ''));
        $existingPlural = trim((string) ($existingLabels['plural'] ?? ''));

        $incomingLabels = isset($payload['labels']) && is_array($payload['labels'])
            ? $payload['labels']
            : [];
        $incomingSingular = trim((string) ($incomingLabels['singular'] ?? ''));
        $incomingPlural = trim((string) ($incomingLabels['plural'] ?? ''));
        $legacyLabel = array_key_exists('label', $payload)
            ? trim((string) ($payload['label'] ?? ''))
            : '';

        $singular = $incomingSingular !== ''
            ? $incomingSingular
            : ($legacyLabel !== '' ? $legacyLabel : $existingSingular);
        if ($singular === '') {
            $singular = trim((string) ($payload['type'] ?? $existing?->type ?? ''));
        }

        $plural = $incomingPlural !== ''
            ? $incomingPlural
            : ($existingPlural !== '' ? $existingPlural : Str::plural($singular));

        return [
            'singular' => $singular,
            'plural' => $plural === '' ? Str::plural($singular) : $plural,
        ];
    }

    /**
     * @return array<string, string>|null
     */
    private function resolvePayloadVisual(TenantProfileType $model, ?string $baseUrl = null): ?array
    {
        $visual = $this->poiVisualNormalizer->normalize($model->visual ?? $model->poi_visual ?? null);
        if (! is_array($visual)) {
            return null;
        }

        if (($visual['mode'] ?? null) !== 'image' || ($visual['image_source'] ?? null) !== 'type_asset') {
            return $visual;
        }

        $rawUrl = $this->normalizeTypeAssetUrl($model->type_asset_url ?? null);
        if ($rawUrl === null) {
            return $visual;
        }

        $visual['image_url'] = $baseUrl !== null
            ? $this->mediaService->normalizePublicUrl($baseUrl, $model, 'type_asset', $rawUrl)
            : $rawUrl;

        return $visual;
    }

    private function ensureTypeAssetRequirements(
        ?array $visual,
        Request $request,
        ?string $existingTypeAssetUrl,
        bool $removeTypeAsset,
    ): void {
        if (! is_array($visual)) {
            return;
        }

        if (($visual['mode'] ?? null) !== 'image' || ($visual['image_source'] ?? null) !== 'type_asset') {
            return;
        }

        $hasUpload = $request->hasFile('type_asset');
        $hasExisting = $existingTypeAssetUrl !== null && ! $removeTypeAsset;
        if ($hasUpload || $hasExisting) {
            return;
        }

        throw ValidationException::withMessages([
            'type_asset' => ['Type asset image is required when image_source is type_asset.'],
        ]);
    }

    private function normalizeTypeAssetUrl(mixed $raw): ?string
    {
        $value = trim((string) $raw);

        return $value === '' ? null : $value;
    }
}
