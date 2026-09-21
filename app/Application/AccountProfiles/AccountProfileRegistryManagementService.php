<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Application\AccountProfiles\Capabilities\AccountProfileCapabilityResolverContract;
use App\Application\Shared\MapPois\PoiVisualNormalizer;
use App\Jobs\AccountProfiles\DispatchAccountProfileOutboxEventJob;
use App\Jobs\AccountProfiles\RefreshAccountProfileSearchForTypeJob;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

class AccountProfileRegistryManagementService
{
    public function __construct(
        private readonly PoiVisualNormalizer $poiVisualNormalizer,
        private readonly AccountProfileTypeMediaService $mediaService,
        private readonly AccountProfileCapabilityResolverContract $capabilityResolver,
        private readonly AccountProfileLocationPolicy $locationPolicy,
        private readonly AccountProfileTypeChangeImpactService $changeImpact,
        private readonly AccountProfileTransactionRunner $transactionRunner,
        private readonly AccountProfileOutboxPublisher $outboxPublisher,
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

        $entry['capability_revision'] = 0;
        $entry['host_admission_fence_revision'] = 0;
        $entry['capabilities'] = $this->capabilityResolver->configurationForPersistence(
            $entry['capabilities'],
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
        $currentLabels = $this->normalizeLabels([], $model);

        if ($nextType !== $currentType) {
            $this->ensureTypeIsNotReferenced($currentType);

            if (TenantProfileType::query()->where('type', $nextType)->exists()) {
                throw ValidationException::withMessages([
                    'type' => ['Profile type already exists.'],
                ]);
            }
        }

        $entry = $this->mergeEntry($model, $payload, $nextType);
        $currentCapabilities = $this->arrayFrom($model->capabilities ?? []);
        $currentPoiEnabled = $this->isEffectiveMapPoiEnabled($model);
        $nextCapabilities = array_key_exists('capabilities', $entry)
            && is_array($entry['capabilities'])
                ? $entry['capabilities']
                : $currentCapabilities;
        $nextPoiEnabled = $this->effectiveValueForConfiguration($model, $nextCapabilities, 'is_map_poi_enabled') === true;
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

        $typeReconcileEventId = null;
        try {
            if (array_key_exists('capabilities', $payload)) {
                $capabilityPatch = is_array($payload['capabilities']) ? $payload['capabilities'] : [];
                [$model, $typeReconcileEventId] = $this->persistCapabilityPatchWithRevision(
                    $model,
                    $entry,
                    $currentCapabilities,
                    $nextCapabilities,
                    $payload['expected_capability_revision'] ?? null,
                    $currentType,
                    $capabilityPatch,
                );
                Event::dispatch('eloquent.saved: '.TenantProfileType::class, $model);
            } else {
                $model->fill($entry);
                $model->save();
            }
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
        $nextLabels = $this->normalizeLabels([], $model);
        if ($nextType === $currentType && $nextLabels !== $currentLabels) {
            DB::connection('tenant')->afterCommit(
                static fn () => RefreshAccountProfileSearchForTypeJob::dispatch($nextType),
            );
        }
        $nextTypeAssetUrl = $this->normalizeTypeAssetUrl($model->type_asset_url ?? null);
        $typeAssetChanged = $currentTypeAssetUrl !== $nextTypeAssetUrl;
        $forcedCheckpoint = $this->toCheckpoint($model->updated_at ?? null);
        $shouldRefreshMapProjection = $nextType !== $currentType
            || $currentPoiEnabled !== $nextPoiEnabled
            || $poiVisualChanged
            || $typeAssetChanged;

        if ($typeReconcileEventId !== null) {
            DispatchAccountProfileOutboxEventJob::dispatch($typeReconcileEventId);
        } elseif ($shouldRefreshMapProjection) {
            $visualRefreshId = (string) Str::uuid();
            $eventId = $this->transactionRunner->run(
                fn (AccountProfileTransactionContext $context): string => $this->outboxPublisher->recordMapPoiTypeReconcile(
                    $context,
                    $nextType,
                    null,
                    $forcedCheckpoint,
                    $visualRefreshId,
                ),
            );
            DispatchAccountProfileOutboxEventJob::dispatch($eventId);
        }

        return $this->toPayload($model, $request->getSchemeAndHttpHost());
    }

    /**
     * @param  array<string, mixed>  $capabilityPatch
     * @return array{profile_type:string,capability_revision:int,missing_location_count:int,map_projection_count:int,event_reference_count:int,sample_profile_ids:array<int, string>}
     */
    public function previewChangeImpact(string $type, array $capabilityPatch): array
    {
        return $this->changeImpact->preview($type, $capabilityPatch);
    }

    public function delete(string $type): void
    {
        $type = trim($type);
        $model = TenantProfileType::query()->where('type', $type)->first();
        if (! $model) {
            abort(404, 'Profile type not found.');
        }

        $this->ensureTypeIsNotReferenced((string) ($model->type ?? ''));

        $model->delete();
    }

    private function ensureTypeIsNotReferenced(string $type): void
    {
        if (! AccountProfile::withTrashed()->where('profile_type', $type)->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'type' => ['Profile type is referenced by an account profile.'],
        ]);
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
            'capabilities' => $this->capabilityResolver->materializeConfigurationForCreation(
                is_array($capabilities) ? $capabilities : [],
            ),
            'capability_revision' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function mergeEntry(TenantProfileType $existing, array $payload, string $resolvedType): array
    {
        $capabilities = $payload['capabilities'] ?? [];
        $currentCapabilities = $this->arrayFrom($existing->capabilities ?? []);
        $visual = $this->resolveIncomingVisual($payload, $existing->visual ?? $existing->poi_visual ?? null);
        $labels = $this->normalizeLabels($payload, $existing);

        $entry = [
            'type' => $resolvedType,
            'label' => $labels['singular'],
            'labels' => $labels,
            'allowed_taxonomies' => array_key_exists('allowed_taxonomies', $payload)
                ? $this->normalizeTaxonomies($payload['allowed_taxonomies'] ?? [])
                : $this->normalizeTaxonomies($existing->allowed_taxonomies ?? []),
            'visual' => $visual,
            'poi_visual' => $visual,
        ];
        if (array_key_exists('capabilities', $payload)) {
            $entry['capabilities'] = $this->capabilityResolver->mergeConfigurationForUpdate(
                is_array($capabilities) ? $capabilities : [],
                $currentCapabilities,
            );
        }

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $capabilities
     * @param  array<string, mixed>  $currentCapabilities
     * @return array<string, array{value:mixed,parameters:array<string,int>}>
     */
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
        $type = trim((string) ($model->type ?? ''));

        return [
            'type' => $type,
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
            'capabilities' => $this->capabilityResolver->resolveAllForProfileType($model),
            'capability_revision' => max(0, (int) ($model->capability_revision ?? 0)),
        ];
    }

    private function isEffectiveMapPoiEnabled(TenantProfileType $type): bool
    {
        return $this->capabilityResolver->resolveForProfileType($type, 'is_map_poi_enabled')['effective']['value'] === true;
    }

    /** @param array<string, mixed> $configuration */
    private function effectiveValueForConfiguration(TenantProfileType $type, array $configuration, string $key): mixed
    {
        $candidate = $type->replicate();
        $candidate->capabilities = $configuration;

        return $this->capabilityResolver->resolveForProfileType($candidate, $key)['effective']['value'];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $currentCapabilities
     * @param  array<string, mixed>  $nextCapabilities
     */
    private function persistCapabilityPatchWithRevision(
        TenantProfileType $model,
        array $entry,
        array $currentCapabilities,
        array $nextCapabilities,
        mixed $expectedRevision,
        string $profileType,
        array $capabilityPatch,
    ): array {
        if (! is_int($expectedRevision)) {
            throw ValidationException::withMessages([
                'expected_capability_revision' => ['The expected capability revision is required when capabilities are changed.'],
            ]);
        }

        $currentRevision = max(0, (int) ($model->capability_revision ?? 0));
        if ($expectedRevision !== $currentRevision) {
            $this->throwCapabilityRevisionConflict($currentRevision);
        }

        unset($entry['capability_revision']);
        $entry['capabilities'] = $this->capabilityResolver->configurationForPersistence(
            $nextCapabilities,
        );
        $semanticChange = $nextCapabilities !== $this->capabilityResolver->mergeConfigurationForUpdate(
            $currentCapabilities,
            $currentCapabilities,
        );
        $update = ['$set' => $entry];
        if ($semanticChange) {
            $update['$inc'] = [
                'capability_revision' => 1,
                'host_admission_fence_revision' => 1,
            ];
        }

        $currentMapEnabled = $this->effectiveValueForConfiguration($model, $currentCapabilities, 'is_map_poi_enabled') === true;
        $nextMapEnabled = $this->effectiveValueForConfiguration($model, $nextCapabilities, 'is_map_poi_enabled') === true;
        $requiresMapReconcile = $semanticChange && $currentMapEnabled !== $nextMapEnabled;

        $eventId = $this->transactionRunner->run(function (AccountProfileTransactionContext $context) use (

            $expectedRevision,
            $update,
            $semanticChange,
            $requiresMapReconcile,
            $profileType,
            $capabilityPatch,
        ): ?string {
            $this->changeImpact->assertMutationAllowed($profileType, $capabilityPatch, $context);
            $result = $context->collection('account_profile_types')->updateOne(
                ['type' => $profileType, 'capability_revision' => $expectedRevision],
                $update,
                $context->rawOptions(),
            );
            if ($result->getMatchedCount() !== 1) {
                $fresh = $context->collection('account_profile_types')->findOne(
                    ['type' => $profileType],
                    $context->rawOptions(),
                );
                $this->throwCapabilityRevisionConflict((int) ($fresh['capability_revision'] ?? 0));
            }

            if (! $requiresMapReconcile) {
                return null;
            }

            return $this->outboxPublisher->recordMapPoiTypeReconcile(
                $context,
                $profileType,
                $semanticChange ? $expectedRevision + 1 : $expectedRevision,
                (int) now()->getTimestampMs(),
            );
        });

        return [
            TenantProfileType::query()->whereKey($model->getKey())->firstOrFail(),
            $eventId,
        ];
    }

    private function throwCapabilityRevisionConflict(int $currentRevision): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'The account profile type capabilities changed since they were loaded.',
            'code' => 'account_profile_type_revision_conflict',
            'current_capability_revision' => max(0, $currentRevision),
        ], 409));
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayFrom(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            return $value->getArrayCopy();
        }

        if ($value instanceof \Traversable) {
            return iterator_to_array($value);
        }

        return [];
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
        ?string $currentTypeAssetUrl,
        bool $removeTypeAsset,
    ): void {
        if (($visual['mode'] ?? null) !== 'image' || ($visual['image_source'] ?? null) !== 'type_asset') {
            return;
        }

        $hasNewUpload = $request->hasFile('type_asset');
        $hasExistingAsset = $currentTypeAssetUrl !== null && $currentTypeAssetUrl !== '' && ! $removeTypeAsset;

        if (! $hasNewUpload && ! $hasExistingAsset) {
            throw ValidationException::withMessages([
                'type_asset' => ['Type asset upload is required when poi_visual uses type_asset.'],
            ]);
        }
    }

    private function normalizeTypeAssetUrl(mixed $value): ?string
    {
        $url = trim((string) $value);

        return $url === '' ? null : $url;
    }
}
