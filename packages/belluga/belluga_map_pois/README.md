# belluga_map_pois

Map + POIs backend package.

## Scope
- Map POI projection runtime and query services.
- Package-owned map endpoints (`/api/v1/map/pois`, `/api/v1/map/near`, `/api/v1/map/filters`).
- Tenant-scoped `map_pois` collection migration/indexes.
- Events integration via contracts/listeners/jobs (no direct App coupling in package `src/`).

## Host bindings required
- `Belluga\\MapPois\\Contracts\\MapPoiSourceReaderContract`
- `Belluga\\MapPois\\Contracts\\MapPoiRegistryContract`
- `Belluga\\MapPois\\Contracts\\MapPoiSettingsContract`
- `Belluga\\MapPois\\Contracts\\MapPoiTenantContextContract`

## Multitenancy
- `map_pois` is tenant-scoped.
- Include `packages/belluga/belluga_map_pois/database/migrations` in `config/multitenancy.php` tenant migration paths.

## Settings namespaces
- `map_ui`: map radius/time-window query defaults.
- `map_ingest`: projection rebuild controls (`rebuild.enabled`, `rebuild.batch_size`).
- `map_security`: map query policy toggles.

## Internal operations
- Rebuild projections with:
  - `php artisan map-pois:rebuild`
  - `php artisan map-pois:rebuild events`
  - `php artisan map-pois:rebuild account_profiles`
  - `php artisan map-pois:rebuild static_assets`
- Optional flags:
  - `--batch-size=<n>` to override tenant configured batch size.
  - `--no-purge` to skip source purge before rebuild.
