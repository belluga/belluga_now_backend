# Belluga Media Package

## Purpose and Bounded Scope
`belluga_media` centralizes reusable media processing primitives for tenant-scoped model media flows (avatar/cover style slots). It is responsible for generic storage-path resolution, canonical URL generation/normalization, and upload/remove slot orchestration.

This package does not own HTTP routes, controller policy decisions, or model-specific authorization rules.

## Domain Concepts and Invariants
- Media slots are explicit (`avatar`, `cover`, or any configured slot list).
- Canonical media URLs remain model-specific but must follow the same normalization policy.
- Tenant scope always participates in storage path resolution.
- Removal commands are explicit via `remove_<slot>` request flags.

## Data Model and Migration Scope
- Scope classification: `tenant`.
- This package ships no migrations and owns no collections.
- It only produces deterministic storage paths under a tenant-resolved directory tree.

## Public Contracts
- `Belluga\Media\Contracts\TenantMediaScopeResolverContract`
  - Host-provided tenant scope resolver (for example tenant slug).
- `Belluga\Media\Support\MediaModelDefinition`
  - Immutable model media contract (legacy/canonical prefixes, storage directory, slots, extensions, fallback scope).
- `Belluga\Media\Application\ModelMediaService`
  - Generic media operations for upload/update/remove/path/URL normalize.

No package HTTP routes or controllers are exposed.

## Authentication and Authorization Boundary
- Package does not inspect authenticated users or abilities.
- Package expects the host layer to apply middleware, tenant access checks, and ability gating before invoking media operations.
- Package only processes request payload/media after host authorization has already passed.

## Host Integration Guide
- Register package provider: `Belluga\Media\MediaServiceProvider`.
- Bind `TenantMediaScopeResolverContract` in host integration provider (for this app: `App\Providers\PackageIntegration\MediaIntegrationServiceProvider`).
- Inject `ModelMediaService` into host domain services and pass `MediaModelDefinition` per aggregate.

## Validation Commands and Test Gates
- Targeted package/core tests:
  - `php artisan test tests/Unit/Media/ModelMediaServiceTest.php`
- Host regression gates:
  - `php artisan test tests/Feature/StaticAssets/StaticAssetsControllerTest.php`
  - `php artisan test tests/Feature/AccountProfiles/AccountProfilesControllerTest.php`
- Package architecture guardrail:
  - `composer run architecture:guardrails`

## Known Limitations and Non-goals
- Does not perform image transforms, transcoding, or CDN integration.
- Does not manage media route registration.
- Does not replace model-specific response payload formatting.

