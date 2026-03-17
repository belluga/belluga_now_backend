<?php

declare(strict_types=1);

namespace App\Application\Tenants;

use App\Models\Landlord\Domains;
use App\Models\Landlord\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MongoDB\Driver\Exception\BulkWriteException;

class TenantAppDomainManagementService
{
    /**
     * @return array{android: ?string, ios: ?string}
     */
    public function list(Tenant $tenant): array
    {
        $identifiers = $tenant->typedAppDomainIdentifiers();
        if ($identifiers[Tenant::APP_PLATFORM_ANDROID] === null
            && $identifiers[Tenant::APP_PLATFORM_IOS] === null) {
            $legacy = $tenant->resolvedAppDomains();
            if (($legacy[0] ?? null) !== null) {
                $identifiers[Tenant::APP_PLATFORM_ANDROID] = $legacy[0];
            }
            if (($legacy[1] ?? null) !== null) {
                $identifiers[Tenant::APP_PLATFORM_IOS] = $legacy[1];
            }
        }

        return $identifiers;
    }

    /**
     * @return array{android: ?string, ios: ?string}
     */
    public function upsert(Tenant $tenant, string $platform, string $identifier): array
    {
        $normalizedPlatform = $this->normalizePlatform($platform);
        $normalizedIdentifier = $this->normalizeIdentifier($identifier);
        if ($normalizedPlatform === null || $normalizedIdentifier === null) {
            throw ValidationException::withMessages([
                'platform' => ['Invalid app platform or identifier.'],
            ]);
        }

        if (! $this->isIdentifierValidForPlatform($normalizedPlatform, $normalizedIdentifier)) {
            throw ValidationException::withMessages([
                'identifier' => ["Invalid identifier format for {$normalizedPlatform}."],
            ]);
        }

        try {
            DB::connection('landlord')->transaction(function () use ($tenant, $normalizedPlatform, $normalizedIdentifier): void {
                $type = $this->domainTypeForPlatform($normalizedPlatform);
                $existing = $tenant->domains()
                    ->withTrashed()
                    ->where('type', $type)
                    ->orderBy('created_at')
                    ->get();

                /** @var Domains|null $primary */
                $primary = $existing->first();
                if ($primary === null) {
                    $tenant->domains()->create([
                        'type' => $type,
                        'path' => $normalizedIdentifier,
                    ]);
                } else {
                    $primary->path = $normalizedIdentifier;
                    if ($primary->trashed()) {
                        $primary->restore();
                    } else {
                        $primary->save();
                    }

                    foreach ($existing->slice(1) as $extra) {
                        if (! $extra instanceof Domains) {
                            continue;
                        }
                        if (! $extra->trashed()) {
                            $extra->delete();
                        }
                        $extra->forceDelete();
                    }
                }
            });
        } catch (BulkWriteException $exception) {
            if (str_contains($exception->getMessage(), 'E11000')) {
                throw ValidationException::withMessages([
                    'identifier' => ['Another tenant already uses this app identifier for this platform.'],
                ]);
            }

            throw ValidationException::withMessages([
                'identifier' => ['Unable to save app identifier right now.'],
            ]);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'identifier' => ['Unable to save app identifier right now.'],
            ]);
        }

        return $this->list($tenant->fresh());
    }

    /**
     * @return array{android: ?string, ios: ?string}
     */
    public function remove(Tenant $tenant, string $platform): array
    {
        $normalizedPlatform = $this->normalizePlatform($platform);
        if ($normalizedPlatform === null) {
            throw ValidationException::withMessages([
                'platform' => ['Invalid app platform.'],
            ]);
        }

        $type = $this->domainTypeForPlatform($normalizedPlatform);
        $existing = $tenant->domains()
            ->where('type', $type)
            ->get();
        if ($existing->isEmpty()) {
            throw ValidationException::withMessages([
                'platform' => ['App identifier not found for this platform.'],
            ]);
        }

        try {
            DB::connection('landlord')->transaction(function () use ($existing): void {
                foreach ($existing as $domain) {
                    if (! $domain instanceof Domains) {
                        continue;
                    }
                    $domain->delete();
                }
            });
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'platform' => ['Unable to remove app identifier right now.'],
            ]);
        }

        return $this->list($tenant->fresh());
    }

    private function normalizePlatform(string $platform): ?string
    {
        $normalized = strtolower(trim($platform));

        return match ($normalized) {
            Tenant::APP_PLATFORM_ANDROID,
            Tenant::APP_PLATFORM_IOS => $normalized,
            default => null,
        };
    }

    private function normalizeIdentifier(string $identifier): ?string
    {
        $normalized = strtolower(trim($identifier));

        return $normalized === '' ? null : $normalized;
    }

    private function isIdentifierValidForPlatform(string $platform, string $identifier): bool
    {
        return match ($platform) {
            Tenant::APP_PLATFORM_ANDROID => (bool) preg_match('/^[a-zA-Z][a-zA-Z0-9_]*(\.[a-zA-Z][a-zA-Z0-9_]*)+$/', $identifier),
            Tenant::APP_PLATFORM_IOS => (bool) preg_match('/^[a-zA-Z0-9-]+(\.[a-zA-Z0-9-]+)+$/', $identifier),
            default => false,
        };
    }

    private function domainTypeForPlatform(string $platform): string
    {
        return match ($platform) {
            Tenant::APP_PLATFORM_ANDROID => Tenant::DOMAIN_TYPE_APP_ANDROID,
            Tenant::APP_PLATFORM_IOS => Tenant::DOMAIN_TYPE_APP_IOS,
            default => throw ValidationException::withMessages([
                'platform' => ['Invalid app platform.'],
            ]),
        };
    }
}
