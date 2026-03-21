# belluga_map_pois

Canonical Map + POIs projection package for tenant discovery surfaces.

This package owns the materialized `map_pois` projection and the read contracts that power map stack, near, and filter queries. It does not own the upstream source aggregates themselves.

## Scope

- Map POI projection runtime for `event`, `account_profile`, and `static` sources.
- Read endpoints for map stacks, nearby items, and filter catalogs.
- Tenant-scoped `map_pois` collection migrations and indexes.
- Rebuild command for projection repair and backfill.
- Host integration via contracts, listeners/jobs, and adapters.

## Domain Concepts

- `ref_type` identifies the source aggregate family:
  - `event`
  - `account_profile`
  - `static`
- `ref_id` is the source aggregate identifier.
- `projection_key` is the unique projection identity and defaults to `{ref_type}:{ref_id}`.
- `exact_key` is the stacking key used to group co-located POIs.
- `source_type` is the normalized source discriminator used in filters and catalog logic.
- `taxonomy_terms` and `tags` are read-side filter dimensions, not write-side source ownership.
- `map_pois` is a projection, not the system of record for events, profiles, or static assets.

## Invariants

- One active projection row per `(ref_type, ref_id)`.
- `projection_key` must remain unique.
- The collection is tenant-scoped.
- Event POIs are deactivated when capability or geometry conditions no longer hold.
- Account profile POIs are removed when profile type is not favoritable or location is missing.
- Static asset POIs are removed when profile type is not enabled or location is missing.

## Data Model

Collection: `map_pois`

Important indexed fields:

- `ref_type + ref_id` unique
- `projection_key` unique
- `location` `2dsphere`
- `is_active + updated_at + _id`
- `active_window_start_at + active_window_end_at + _id`
- `category + updated_at + _id`
- `ref_type + source_type + updated_at + _id`

Stored fields include:

- source identity and path metadata: `ref_type`, `ref_id`, `ref_slug`, `ref_path`
- display metadata: `name`, `subtitle`, `category`, `badge`
- media metadata: `avatar_url`, `cover_url`
- location and discovery metadata: `location`, `discovery_scope`, `exact_key`
- filter metadata: `tags`, `taxonomy_terms`, `taxonomy_terms_flat`, `occurrence_facets`
- activity metadata: `is_active`, `is_happening_now`, `priority`, time windows

## Public Contracts

Host routes are tenant-authenticated and tenant-access protected:

- `GET /api/v1/map/pois`
- `GET /api/v1/map/near`
- `GET /api/v1/map/filters`

Route ownership lives in the host app. The package provides the controller and query service only.

### `GET /api/v1/map/pois`

Returns stacked POIs for a bounded map area.

Query inputs:

- `ne_lat`, `ne_lng`, `sw_lat`, `sw_lng`
- `stack_key` optional
- `source` optional
- `types[]` optional

Response shape:

- `tenant_id`
- `server_time`
- `bounds`
- `stacks[]`

Each stack exposes:

- `stack_key`
- `center`
- `stack_count`
- `top_poi`

### `GET /api/v1/map/near`

Returns paginated nearby POIs.

Query inputs:

- `origin_lat`, `origin_lng`
- `page`
- `page_size`
- optional bounding and filtering parameters supported by the controller request

Response shape:

- `tenant_id`
- `page`
- `page_size`
- `has_more`
- `items[]`

### `GET /api/v1/map/filters`

Returns filter catalogs derived from current POIs.

Response shape:

- `tenant_id`
- `categories`
- `tags`
- `taxonomy_terms`

## Auth Boundary

The package reads the current tenant context and user timezone from the host-resolved request context, but it does not own authentication middleware.

- Package code depends on `request()->user()` for user timezone hints only.
- Host routes must apply `auth:sanctum` and `CheckTenantAccess`.
- The package does not register the host middleware itself.

## Host Bindings Required

- `Belluga\MapPois\Contracts\MapPoiSourceReaderContract`
- `Belluga\MapPois\Contracts\MapPoiRegistryContract`
- `Belluga\MapPois\Contracts\MapPoiSettingsContract`
- `Belluga\MapPois\Contracts\MapPoiTenantContextContract`

## Host Integration

The host app must provide adapters for:

- source reading from events, account profiles, and static assets
- registry decisions for favoritable/static POI types
- tenant context resolution
- settings resolution for map UI and ingest behavior

The host app also owns the route files that mount:

- `Belluga\MapPois\Http\Api\v1\Controllers\MapPoisController`
- `auth:sanctum` + `CheckTenantAccess`

## Settings Namespaces

- `map_ui`: map radius/time-window query defaults.
- `map_ingest`: projection rebuild controls (`rebuild.enabled`, `rebuild.batch_size`).
- `map_security`: map query policy toggles.

## Internal Operations

Projection repair and backfill:

- `php artisan map-pois:rebuild`
- `php artisan map-pois:rebuild events`
- `php artisan map-pois:rebuild account_profiles`
- `php artisan map-pois:rebuild static_assets`

Optional flags:

- `--batch-size=<n>` overrides tenant configured batch size.
- `--no-purge` keeps existing projections and only upserts.

## Multitenancy

- `map_pois` is tenant-scoped.
- Include `packages/belluga/belluga_map_pois/database/migrations` in `config/multitenancy.php` tenant migration paths.
- Do not persist `tenant_id` inside `map_pois`; the tenant database is the boundary.

## Validation Commands

- `php artisan test tests/Feature/Map/MapPoisControllerTest.php`
- `php artisan map-pois:rebuild`
- `composer run architecture:guardrails`

## Known Limitations / Non-Goals

- No direct write API exists in this package.
- It is a projection layer only; source-of-truth mutations live in the upstream domain packages.
- Search is not a first-class contract in the public map endpoints unless the host requests it via the current query contract.
- The package does not own tenant resolution or auth policy decisions.
