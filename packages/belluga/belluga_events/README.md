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

## Current Delivery Status

Implemented and locked:
- occurrence-first read/stream model
- two-collection persistence (`events` + `event_occurrences`)
- event publication as single source of truth, mirrored to occurrences
- stream reconnect policy without replay buffer
- ACL/event-parties foundation (`created_by`, `event_parties`, `can_edit`)
- settings-kernel integration for capability gating (`events` namespace)
- pilot capability `multiple_occurrences`

Deferred (still pending):
- final capability block (ticketing capabilities: inventory, qr_checkin, combo, limits, participant/student binding, pricing fees)

---

## Core Decisions Clients Must Know

1. Contract is occurrence-first.
- Stream events are `occurrence.created|occurrence.updated|occurrence.deleted`.
- Deltas always include both `event_id` and `occurrence_id`.

2. No backward-compatibility bridge.
- Clients must use the current contract shape.
- Legacy `account_id`/`account_profile_id` event fields are removed from Events payload/model/contracts.

3. Invite lifecycle data is out of Events scope.
- Events payloads do not expose:
  - `received_invites`
  - `sent_invites`
  - `friends_going`
  - `is_confirmed`
  - `total_confirmed`

4. Publication source of truth is Event-level.
- Occurrence publication flags are derived mirrors for query performance only.

5. Events are tenant-db scoped.
- `event_occurrences` documents do not persist `tenant_id`.

Decision traceability (program IDs):
- `D3-01` two-collection model
- `D3-02` event-level publication source of truth
- `D3-03` occurrence-first stream contract
- `D3-04` occurrence-first query/index strategy
- `D3-05` hard cutover without compatibility bridge
- `D3-06..D3-11` capability governance + effective gate + migration policy
- `D3-16` pilot capability payload rules
- `D3-17` invite boundary
- `D3-18..D3-23` ACL/event-parties model and permission policy

---

## Package Boundaries

This package owns:
- Event aggregate write/read services
- occurrence synchronization and reconciliation
- stream delta generation
- capability runtime registry/handlers
- package migrations/indexes

This package does not own:
- invite transaction lifecycle
- map/account-profile domain rules
- tenant resolution strategy details

Those integrations are host-bound through contracts.

---

## Persistence Model

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

---

## API Surfaces (Host Routes)

The package provides controllers/requests used by host route files.

Tenant public scope:
- `GET /api/v1/agenda`
- `GET /api/v1/events`
- `GET /api/v1/events/{event_id}`
- `GET /api/v1/events/stream`

Tenant admin scope:
- `GET /admin/api/v1/events`
- `POST /admin/api/v1/events`
- `PATCH /admin/api/v1/events/{event_id}`
- `DELETE /admin/api/v1/events/{event_id}`
- `GET /admin/api/v1/events/stream`
- `GET /admin/api/v1/events/{event_id}`

Account scope:
- `GET /api/v1/accounts/{account_slug}/events`
- `POST /api/v1/accounts/{account_slug}/events`
- `PATCH /api/v1/accounts/{account_slug}/events/{event_id}`
- `DELETE /api/v1/accounts/{account_slug}/events/{event_id}`
- `GET /api/v1/accounts/{account_slug}/events/{event_id}`

Auth/guard expectations are defined by host routes/middleware (`auth:sanctum`, `tenant`, `CheckTenantAccess`, `account`).

---

## Read Contracts

### `GET /agenda`

Query:
- `page`, `page_size`
- `past_only`
- `search`
- `categories[]`
- `tags[]`
- `taxonomy[]` (`{type, value}`)
- `origin_lat`, `origin_lng`, `max_distance_meters`

Response item (minimum):
- `event_id`
- `occurrence_id`
- `slug`
- `type`
- `title`, `content`
- `venue`, `artists`
- `latitude`, `longitude`
- `date_time_start`, `date_time_end`
- `occurrences[]`
- `created_by`
- `event_parties[]`
- `capabilities`
- `tags`, `taxonomy_terms`

### `GET /events/{event_id}`

Behavior:
- accepts ObjectId or slug
- in public context, unpublished/future scheduled events return `404`

Response shape:
- same contract family as agenda item
- `occurrences[]` included
- invite lifecycle fields excluded

### `GET /events/stream` (SSE)

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

---

## Write Contracts

### Create (`POST /events`)

Required:
- `title`
- `content`
- `venue_id`
- `type` (`name`, `slug`; optional: `id`, `description`, `icon`, `color`)
- `occurrences[]` (at least 1)
- `publication.status`

Optional:
- `artist_ids[]`
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

### Update (`PATCH /events/{event_id}`)

Partial update by field presence.

Important schedule rule:
- `occurrences` omitted: schedule is preserved from stored occurrences
- `occurrences` present: full schedule mutation validated and re-synced

Delete:
- soft delete only

---

## ACL and Event Parties

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

---

## Capability Model and Settings Integration

Effective runtime rule:
- `effective_capability = tenant_available && event_enabled`

Tenant capability settings come from settings-kernel namespace `events`:
- `capabilities.multiple_occurrences.allow_multiple` (bool)
- `capabilities.multiple_occurrences.max_occurrences` (int|null)

Event-level usage:
- `capabilities.multiple_occurrences.enabled` (bool)

Normalization:
- tenant `max_occurrences=0` is normalized to `null`

Enforcement:
- if effective capability is false, schedule must not contain multiple occurrences
- if effective capability is true and `max_occurrences` is numeric, schedule count must be <= max

Disable/reenable behavior:
- non-destructive
- config is preserved while disabled

---

## Host Integration Contracts (Required Bindings)

Host app must bind:
- `EventTaxonomyValidationContract`
- `EventProfileResolverContract`
- `EventAccountResolverContract`
- `EventCapabilitySettingsContract`
- `EventPartyMapperRegistryContract`
- `EventTenantContextContract`
- `EventProjectionSyncContract`
- `EventRadiusSettingsContract`
- `TenantExecutionContextContract`

If a binding is missing, provider fails fast at runtime.

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
- filtering (`venue.id`, `categories`, `tags`, typed taxonomy terms)
- geo (`venue_geo` 2dsphere)
- occurrence identity (`event_id`, `occurrence_index`, unique)

Tenant migration model:
- events and occurrences are migrated in tenant databases (Spatie multitenancy flow)

---

## Multitenancy Classification (Required)

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

## Testing Gates

Primary suites:
- `tests/Feature/Events/EventCrudControllerTest.php`
- `tests/Feature/Events/AgendaAndEventsControllerTest.php`
- `tests/Unit/Events/EventsPackageBindingsTest.php`
- `tests/Unit/Events/EventsAsyncOperationalPolicyTest.php`
- `tests/Unit/Events/EventAsyncOperationsMonitorServiceTest.php`

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
6. Handle reconnect by rehydrating `/agenda` when cursor is missing/invalid.

---

## Deferred Scope

The following remain intentionally outside the delivered block:
- consolidated ticketing capabilities (inventory, qr_checkin, combo, limits, participant/student binding, pricing fees)
- their tenant-scoped migration/index expansion and dedicated integration tests
