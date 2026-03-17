<?php

declare(strict_types=1);

use App\Models\Landlord\Tenant;
use App\Models\Tenants\TenantSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Arr;

return new class extends Migration
{
    public function up(): void
    {
        Tenant::query()->get()->each(function (Tenant $tenant): void {
            $tenant->makeCurrent();

            try {
                $legacyAppDomains = $tenant->resolvedAppDomains();
                $settings = TenantSettings::current();
                $appLinks = $this->normalizeAppLinks($settings?->getAttribute('app_links'));

                $androidIdentifier = $this->firstNonEmpty([
                    data_get($appLinks, 'android.package_name'),
                    $legacyAppDomains[0] ?? null,
                ]);
                $iosIdentifier = $this->firstNonEmpty([
                    data_get($appLinks, 'ios.bundle_id'),
                    $legacyAppDomains[1] ?? null,
                ]);

                if ($androidIdentifier !== null) {
                    $this->upsertTypedDomain(
                        tenant: $tenant,
                        type: Tenant::DOMAIN_TYPE_APP_ANDROID,
                        identifier: $androidIdentifier,
                    );
                }

                if ($iosIdentifier !== null) {
                    $this->upsertTypedDomain(
                        tenant: $tenant,
                        type: Tenant::DOMAIN_TYPE_APP_IOS,
                        identifier: $iosIdentifier,
                    );
                }

                if ($settings !== null) {
                    Arr::forget($appLinks, 'android.package_name');
                    Arr::forget($appLinks, 'ios.bundle_id');
                    $settings->app_links = $appLinks;
                    $settings->save();
                }
            } finally {
                $tenant->forgetCurrent();
            }
        });
    }

    public function down(): void
    {
        // No destructive rollback. Typed app identifiers are now canonical.
    }

    /**
     * @param  array<int, mixed>  $candidates
     */
    private function firstNonEmpty(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $normalized = strtolower(trim($candidate));
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    private function upsertTypedDomain(Tenant $tenant, string $type, string $identifier): void
    {
        $existing = $tenant->domains()
            ->withTrashed()
            ->where('type', $type)
            ->orderBy('created_at')
            ->first();

        if ($existing === null) {
            $tenant->domains()->create([
                'type' => $type,
                'path' => $identifier,
            ]);

            return;
        }

        $existing->path = $identifier;
        if ($existing->trashed()) {
            $existing->restore();

            return;
        }

        $existing->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeAppLinks(mixed $raw): array
    {
        if ($raw instanceof \MongoDB\Model\BSONDocument || $raw instanceof \MongoDB\Model\BSONArray) {
            return Arr::undot($raw->getArrayCopy());
        }
        if (is_array($raw)) {
            return Arr::undot($raw);
        }
        if ($raw instanceof \Traversable) {
            return Arr::undot(iterator_to_array($raw));
        }
        if (is_object($raw)) {
            return Arr::undot((array) $raw);
        }

        return [];
    }
};
