# Belluga Events Package (`belluga/events`)

Canonical reference for teams integrating with the Events package.

This README is the source of truth for:
- runtime behavior
- API contracts
- extension points
- operational guarantees
- hard cutover decisions already enforced

If a client (backend consumer, Flutter, web) needs to integrate with Events, use this document first.

---

## Purpose and Scope

This package owns the canonical event and occurrence runtime for the Belluga ecosystem.
It handles event write/read behavior, occurrence synchronization, stream deltas, capability gating, and package migrations for the tenant database.

It does not own invite lifecycle, addon admission/entitlement flows, or tenant resolution strategy.

---

## Current Delivery Status

Implemented and locked:
- occurrence-first read/stream model
- two-collection persistence (`events` + `event_occurrences`)
- event publication as single source of truth, mirrored to occurrences
- stream reconnect policy without replay buffer
- ACL/event-parties foundation (`created_by`, `event_parties`, `can_edit`)
- settings-kernel integration for capability gating (`events` namespace)
- pilot capability `multiple_occurrences`
- capability `map_poi` (tenant/event gate + geometry contract + projection semantics)

Deferred (still pending):
- final addon capability blocks owned by external packages (inventory, check-in, bundle/combo, access limits, attendee/student binding, pricing fees)

---

## Domain Concepts and Invariants

### Event Parties (`event_parties`)
- Represents entities that are part of composing the event itself.
- Examples: artists, artisans, hosts, venues, organizers.
- Domain ownership: **Events**.
- Purpose: content composition + ACL/edit permissions.

### Attendees / Students (Attendance Binding)
- Represents people who will attend or consume access to the event or occurrence.
- Examples: students, ticket holders, invited attendees.
- Domain ownership: **Participation package** (not Events).
- Purpose: eligibility, entitlement, access validation, and presence/check-in.

Rule: `event_parties` must never be reused as attendee/student binding.

Other invariants:
- Contract is occurrence-first.
- Legacy `account_id` / `account_profile_id` event fields are removed from payloads, models, and contracts.
- Invite lifecycle data is out of scope for Events payloads.
- Publication source of truth is event-level; occurrence publication flags are derived mirrors only.
- Events are tenant-db scoped; `event_occurrences` documents do not persist `tenant_id`.

---

## Data Model and Migrations

Collections:
- `events`: canonical identity + publication source + content metadata
- `event_occurrences`: occurrence-level query unit for agenda/filter/stream

Key occurrence fields:
- `event_id`
- `occurrence_index`
- `starts_at`
- `ends_at`
- `is_event_published`
- `updated_at`
- `deleted_at`
- mirrored fields for read/filter (`venue`, `artists`, `tags`, `taxonomy_terms`, etc.)

Identity rule:
- unique per event occurrence: `(event_id, occurrence_index)`

Migration scope:
- tenant-only package migrations loaded from `database/migrations`

---

## Public Contracts

The package provides controllers and requests used by host route files.

### Host routes

Tenant public scope:
- `GET /api/v1/agenda`
- `GET /api/v1/events`
- `GET /api/v1/events/{event_id}`
- `GET /api/v1/events/stream`
- `GET /media/events/{event_id}/cover`

Tenant admin scope:
- `GET /events/account_profile_candidates`
- `GET /admin/api/v1/events`
- `POST /admin/api/v1/events`
- `PATCH /admin/api/v1/events/{event_id}`
- `DELETE /admin/api/v1/events/{event_id}`
- `GET /admin/api/v1/events/stream`
- `GET /admin/api/v1/events/{event_id}`
- `GET /event_types`
- `POST /event_types`
- `PATCH /event_types/{event_type}`
- `DELETE /event_types/{event_type}`

Account scope:
- `GET /api/v1/accounts/{account_slug}/events`
- `POST /api/v1/accounts/{account_slug}/events`
- `PATCH /api/v1/accounts/{account_slug}/events/{event_id}`
- `DELETE /api/v1/accounts/{account_slug}/events/{event_id}`
- `GET /api/v1/accounts/{account_slug}/events/{event_id}`

Management candidate discovery note:
- `GET /events/account_profile_candidates` uses `type=related_account_profile|physical_host`.
- `related_account_profile` is the generic event-party picker contract and must not hardcode one dynamic profile type such as `artist`.

Auth and guard expectations are defined by host routes and middleware (`auth:sanctum`, `tenant`, `CheckTenantAccess`, `account`).

### Read contracts

#### `GET /agenda`

Query:
- `page`, `page_size`
- `past_only`
- `categories[]`
- `tags[]`
- `taxonomy[]` (`{type, value}`)
- `origin_lat`, `origin_lng`, `max_distance_meters`

Notes:
- `search` is intentionally unsupported in MVP for agenda/events listing.
- `taxonomy[].type` and `taxonomy[].value` are slug pairs (`taxonomy.slug`, `taxonomy_term.slug`).

Response item minimum:
- `event_id`
- `occurrence_id`
- `slug`
- `type`
- `title`, `content`
- `location`, `place_ref`
- `venue` (resolved projection from the host physical-host resolver)
- `artists`
- `latitude`, `longitude`
- `date_time_start`, `date_time_end`
- `occurrences[]`
- `created_by`
- `event_parties[]`
- `linked_account_profiles[]`
- `capabilities`
- `tags`, `taxonomy_terms`
- `artists` remains a public read projection; management CRUD/detail responses should rely on `event_parties` + `linked_account_profiles` and may omit `artists`.

#### `GET /events/{event_id}`

Behavior:
- accepts ObjectId or slug
- in public context, unpublished or future scheduled events return `404`

Response shape:
- same contract family as agenda item
- `occurrences[]` included
- invite lifecycle fields excluded

#### `GET /events/stream` (SSE)

Delta shape:
```json
{
  "event_id": "string",
  "occurrence_id": "string",
  "type": "occurrence.created|occurrence.updated|occurrence.deleted",
  "updated_at": "2025-01-01T00:00:00Z"
}
```

Reconnect policy:
- cursor from `Last-Event-ID`
- invalid cursor => empty delta payload (`200`)
- reconnect without usable cursor => rehydrate `/agenda` page 1, then continue stream from now
- no replay retention buffer

### Write contracts

#### Create (`POST /events`)

Required:
- `title`
- `location.mode`
- `type` (`name`, `slug`; optional: `id`, `description`, `icon`, `color`)
- `occurrences[]` (at least 1)
- `publication.status`

Optional:
- `content`
- `location.geo` (`Point`, coordinates)
- `location.online` (required for `online|hybrid`)
- `place_ref` (`{type,id,metadata?}`; required for `physical|hybrid`, `type=account_profile`)
- `tags[]`
- `categories[]`
- `taxonomy_terms[]`
- `thumb`
- `publication.publish_at`
- `capabilities.multiple_occurrences.enabled`
- `event_parties[]`

Prohibited:
- `date_time_start`
- `date_time_end`
- `venue_id`

Location mode rules:
- `physical`: requires `place_ref`; geographic basis comes from resolved place or `location.geo`.
- `online`: requires `location.online`; `place_ref` is optional.
- `hybrid`: requires both `place_ref` and `location.online`.

#### Update (`PATCH /events/{event_id}`)

Partial update by field presence.

Important schedule rule:
- `occurrences` omitted: schedule is preserved from stored occurrences
- `occurrences` present: full schedule mutation validated and re-synced

Geo filtering rule (`agenda` + `stream`):
- when geo filters are sent (`origin_lat`, `origin_lng`), only events with valid geographic basis are returned.
- no fallback from geo query to non-geo query.

Delete:
- soft delete only

### ACL and Event Parties

Canonical persisted fields:
- `created_by`: typed principal `{type, id}` (audit identity)
- `event_parties[]`: ACL parties

Party shape:
```json
{
  "party_type": "venue|artist|...",
  "party_ref_id": "string",
  "permissions": { "can_edit": true },
  "metadata": {}
}
```

Authorization precedence:
1. owner/admin override
2. `event_parties` with `can_edit=true`
3. deny

Current mutable action surface gated by `can_edit`:
- update
- delete (soft)
- publish
- unpublish

Unknown `party_type`:
- validation error (no silent pass-through)

Defaults:
- each mapper provides default `can_edit`
- row payload may override default

### Capability model and settings integration

Effective runtime rule:
- `effective_capability = tenant_available && event_enabled`

Tenant capability settings come from settings-kernel namespace `events`:
- `capabilities.multiple_occurrences.allow_multiple` (bool)
- `capabilities.multiple_occurrences.max_occurrences` (int|null)

Event-level usage:
- `capabilities.multiple_occurrences.enabled` (bool)
- `capabilities.map_poi.enabled` (bool, default `true`)
- `capabilities.map_poi.discovery_scope` (optional)
  - `type=point`: requires `point:{type:Point,coordinates:[lng,lat]}`
  - `type=range|circle`: requires `center:{type:Point,coordinates:[lng,lat]}` + `radius_meters`
  - `type=polygon`: requires `polygon:{type:Polygon,coordinates:[...]}`

Normalization:
- tenant `max_occurrences=0` is normalized to `null`

Enforcement:
- if effective capability is false, schedule must not contain multiple occurrences
- if effective capability is true and `max_occurrences` is numeric, schedule count must be <= max

Disable/reenable behavior:
- non-destructive
- config is preserved while disabled

### `map_poi` capability runtime behavior

Tenant key:
- `events.capabilities.map_poi.available` (bool, default `true`)

Event payload key:
- `capabilities.map_poi.enabled`
- `capabilities.map_poi.discovery_scope` (optional regional/online discovery geometry)

Effective gate:
- map projection side effects run only when:
  - tenant `map_poi.available=true`
  - event `capabilities.map_poi.enabled=true`

Projection semantics:
- one consolidated POI projection per event (`projection_key=event:{event_id}`)
- occurrence details are projected as `occurrence_facets[]`
- each facet includes `effective_end` and `is_happening_now`
- stale/ineligible transitions are soft-hide (`is_active=false`) instead of hard delete
- projection writes are idempotent by monotonic `source_checkpoint` (stale retries are ignored)

Happening-now/staleness rule:
- `is_happening_now=true` when `date_time_start <= now < effective_end`
- `effective_end = date_time_end` when present
- fallback `effective_end = date_time_start + 3h` when end is absent

Geometry compatibility:
- canonical persisted geo is always `Point` in `location`
- `range/circle` persists `center + radius_meters` in `discovery_scope`
- `polygon` persists GeoJSON polygon in `discovery_scope.polygon`
- randomized/jitter coordinates are never persisted

---

## Authentication and Authorization Boundary

- Public read endpoints are exposed through host routes and depend on host middleware and guard selection.
- Admin and account routes require host-owned auth and ability gates.
- The package does not implement authentication; it only consumes the contracts and context provided by the host.
- If a binding is missing, the provider fails fast at runtime.

---

## Host Integration Steps

1. Register `EventsServiceProvider`.
2. Bind the required host contracts:
   - `EventTaxonomyValidationContract`
   - `EventTypeResolverContract`
   - `EventProfileResolverContract`
   - `EventAccountResolverContract`
   - `EventAttendanceReadContract`
   - `EventCapabilitySettingsContract`
   - `EventContentSanitizerContract`
   - `EventPartyMapperRegistryContract`
   - `EventTenantContextContract`
   - `EventRadiusSettingsContract`
   - `TenantExecutionContextContract`
3. Mount the host route files that expose the public, admin, and account event endpoints.
4. Keep tenant migrations pointed at `packages/belluga/belluga_events/database/migrations`.
5. Let the package queue failure hook emit DLQ alerts through `EventDlqAlertService`.

---

## Observability and Operations

Structured logs include:
- write lifecycle (`events_write_completed`)
- stream delta build (`events_stream_deltas_built`)
- publication transition (`events_publication_transition_applied`)

Operational guardrails (`OD-04`):
- retry/backoff for async listeners
- queue staleness monitor (`>60s` over 5 minutes)
- DLQ alert hook on queue failures
- occurrence reconciliation cadence (15 minutes)

---

## Migrations and Indexes

Migrations are loaded from package:
- `database/migrations/*`

Important index families:
- agenda ordering/pagination (`deleted_at`, `is_event_published`, `starts_at`, `_id`)
- stream deltas (`updated_at`, `_id`) + soft-delete path
- event timeline/sync (`event_id`, `starts_at`)
- filtering (`place_ref.type/id`, `categories`, `tags`, typed taxonomy terms on event + venue + artists)
- geo (`geo_location` 2dsphere)
- occurrence identity (`event_id`, `occurrence_index`, unique)

Tenant migration model:
- events and occurrences are migrated in tenant databases (Spatie multitenancy flow)

Before adding any migration, classify it explicitly:
- `tenant`: runs in tenant-isolated DBs.
- `landlord`: runs in landlord DB only.
- `mixed`: package has both tenant and landlord migrations, split by directory.

Current classification for `belluga_events`:
- `tenant` only.

Rules for this package:
- Keep package migrations in `packages/belluga/belluga_events/database/migrations`.
- Ensure host config includes this path in `config/multitenancy.php` `tenant_migration_paths`.
- Execute through tenant flow (for example, `tenants:artisan ... --path=packages/belluga/belluga_events/database/migrations`).
- Do not persist `tenant_id` in Events collections, because each tenant has its own isolated database.

If future landlord data is introduced:
- Create a dedicated `database/migrations_landlord` directory.
- Run landlord migrations only on landlord connection/path.
- Never run tenant migrations on landlord DB (or landlord migrations on tenant DBs).

---

## Validation Commands

Recommended checks:
- `php artisan test tests/Feature/Events/EventCrudControllerTest.php`
- `php artisan test tests/Feature/Events/AgendaAndEventsControllerTest.php`
- `php artisan test tests/Unit/Events/EventsPackageBindingsTest.php`
- `php artisan test tests/Unit/Events/EventsAsyncOperationalPolicyTest.php`
- `php artisan test tests/Unit/Events/EventAsyncOperationsMonitorServiceTest.php`
- `php artisan test`

Hard-cutover validation:
- no dependency on legacy event `account_*` fields
- no invite lifecycle fields in Events payload contracts

---

## Client Integration Checklist

Before integrating any client with this package:
1. Consume occurrence-first stream contract (`occurrence.*` + `occurrence_id`).
2. Do not expect invite lifecycle fields in agenda/detail payloads.
3. Use `occurrences[]` as schedule source; treat `date_time_start/end` as first occurrence projection.
4. Do not send legacy `date_time_start/date_time_end` in write payloads.
5. If tenant UI supports multiple occurrences, wire against settings-kernel `events` namespace.
6. Handle reconnect by rehydrating `/agenda` when cursor is missing or invalid.

---

## Known Limitations and Non-Goals

The following remain intentionally outside the delivered block:
- consolidated addon capabilities (inventory, check-in, combo, limits, participant/student binding, pricing fees)
- their tenant-scoped migration/index expansion and dedicated integration tests in external addon packages
- compatibility bridge to removed legacy `account_*` event fields
- invite lifecycle ownership
