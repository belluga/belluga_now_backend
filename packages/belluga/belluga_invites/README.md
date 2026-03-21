# Belluga Invites Package (`belluga/invites`)

Canonical reference for the invites package.

This README is the source of truth for:
- runtime behavior
- API contracts
- host integration points
- persistence model
- auth and authorization boundaries
- validation and operational expectations

If a client, adapter, or integration needs to work with invites, read this document first.

---

## Current Delivery Status

Implemented and locked:
- tenant-scoped invite lifecycle
- grouped invite feed projection
- share-code creation, preview, and materialization
- direct invite accept/decline flows with idempotency
- contact import and contact-hash matching
- realtime delta stream backed by outbox events
- quota counters and command idempotency tracking
- host-provided identity, attendance, telemetry, and target-read contracts

Package boundaries are intentional:
- the package owns invite behavior and persistence
- the host app owns authentication, tenant access middleware, and concrete gateway adapters

---

## Package Boundaries

This package owns:
- invite edges and grouped feed projection
- share-code lifecycle
- contact import matching records
- quota counters and idempotency records
- realtime delta emission
- runtime invite settings and target resolution
- package migrations and indexes

This package does not own:
- token issuance or auth strategy
- tenant access middleware
- event/occurrence read models
- user identity resolution details
- telemetry transport implementation

Those responsibilities are injected through host contracts.

---

## Domain Concepts

### Invite Edge
- Canonical invitation record for one receiver and one event target.
- Stores inviter principal, receiver, target ref, status, acceptance state, and projection fields.
- Feeds the invite list and the attendance attribution logic.

### Share Code
- Short-lived public code that can be previewed without auth.
- Materialization converts a share code into an invite edge for the authenticated viewer.

### Feed Projection
- Grouped read model for the invite feed.
- One projection row can contain multiple inviter candidates for the same target group.

### Contact Hash Directory
- Stores imported contact hashes and their matched users.
- Used to resolve direct invite recipients from imported contacts.

### Principal Social Metrics
- Per-principal aggregate counters used by invite feed/social-proof behavior.

### Quota Counter
- Windowed tenant/user quota tracking for send/share operations.

### Command Idempotency
- Replay protection for accept/decline/share materialization style mutations.

---

## Invariants

- Invite mutations require an authenticated user context.
- Anonymous identities are rejected for auth-required mutations.
- Share preview is public, but materialization is authenticated.
- Public event targets must be published before they can be invited to.
- Multi-occurrence events require a concrete occurrence reference when the target cannot be inferred.
- Accept/decline operations are idempotent and replay-safe.
- Invite feed is grouped by target and receiver context, not raw edge count.
- The package remains tenant-scoped; tenant isolation comes from the tenant database boundary.

---

## Persistence Model

Collections:
- `invite_edges`
- `invite_feed_projection`
- `invite_outbox_events`
- `invite_quota_counters`
- `contact_hash_directory`
- `invite_share_codes`
- `principal_social_metrics`
- `invite_command_idempotencies`

Important indexes:
- `invite_edges`:
  - receiver/status/date ordering
  - receiver/event/occurrence/status lookups
  - inviter principal + creation ordering
  - issued-by-user ordering
  - unique target/receiver/principal constraint
- `invite_feed_projection`:
  - unique `(receiver_user_id, group_key)`
  - receiver/date ordering
  - target lookup
- `invite_outbox_events`:
  - receiver/available-at ordering
  - status/available-at ordering
  - unique `dedupe_key`
- `invite_quota_counters`:
  - unique `(scope, scope_id, window_key)`
- `contact_hash_directory`:
  - unique `(importing_user_id, contact_hash)`
- `invite_share_codes`:
  - unique `code`
  - event/occurrence and inviter-principal lookups
- `principal_social_metrics`:
  - unique `(principal_kind, principal_id)`
- `invite_command_idempotencies`:
  - unique `(command, actor_user_id, idempotency_key)`

Migration scope:
- tenant-scoped only
- package migrations live under `packages/belluga/belluga_invites/database/migrations`
- the tenant DB is the boundary; `tenant_id` is not persisted in these collections

---

## Public Contracts

The package provides controllers and requests used by host route files. Route ownership lives in the host app.

### Host routes

Tenant public scope:
- `GET /api/v1/invites/share/{code}`

Tenant-authenticated scope:
- `GET /api/v1/invites`
- `GET /api/v1/invites/settings`
- `GET /api/v1/invites/stream`
- `POST /api/v1/invites`
- `POST /api/v1/invites/{invite_id}/accept`
- `POST /api/v1/invites/{invite_id}/decline`
- `POST /api/v1/invites/share`
- `POST /api/v1/invites/share/{code}/materialize`
- `POST /api/v1/contacts/import`

### Request payloads

- `POST /invites`
  - `target_ref.event_id` required
  - `target_ref.occurrence_id` optional unless the target event has multiple occurrences
  - `account_profile_id` optional
  - `recipients[]` required
  - each recipient must include `receiver_user_id` or `contact_hash`
  - `message` optional
- `POST /invites/{invite_id}/accept`
  - `idempotency_key` optional
- `POST /invites/{invite_id}/decline`
  - `idempotency_key` optional
- `POST /invites/share`
  - `target_ref.event_id` required
  - `target_ref.occurrence_id` optional unless the target event has multiple occurrences
  - `account_profile_id` optional
- `POST /invites/share/{code}/materialize`
  - `idempotency_key` optional
- `POST /contacts/import`
  - `contacts[]` required
  - each contact has `type` (`phone|email`) and `hash`
  - `salt_version` optional
- `GET /invites`
  - `page` optional
  - `page_size` optional

### Response contracts

- Feed responses return `tenant_id`, `invites[]`, and `has_more`.
- Share preview returns `tenant_id`, `code`, `target_ref`, `inviter_principal`, and nested `invite` preview data.
- Mutations return structured status payloads and may include `rejected` responses from domain errors.
- Realtime stream emits SSE frames with `id`, `event`, and JSON `data`.

---

## Auth Boundary

The package requires an authenticated user context for all mutating operations.

- The package reads the user only through `request()->user()` / `$request->user()`.
- The package does not own the auth implementation, token format, or guard strategy.
- The host must provide the middleware and guard stack for authenticated routes.

Current host expectations:
- authenticated invite routes use `auth:sanctum`
- tenant-authenticated invite routes also use `CheckTenantAccess`
- the host binds the required gateway contracts

Anonymous handling:
- anonymous identities are rejected with `401 auth_required` for mutations that require a real authenticated account
- preview-only endpoints may still be public when the flow explicitly allows it

---

## Host Bindings Required

The package expects the host to bind:
- `Belluga\Invites\Contracts\InviteIdentityGatewayContract`
- `Belluga\Invites\Contracts\InviteAttendanceGatewayContract`
- `Belluga\Invites\Contracts\InviteTelemetryEmitterContract`
- `Belluga\Invites\Contracts\InviteTargetReadContract`

The package service provider registers fail-fast placeholders if the host forgets these bindings.

---

## Host Integration

The host app provides the concrete adapters for:
- inviter principal resolution and recipient lookup
- attendance-confirmation checks
- telemetry emission
- event/occurrence read access

The host app wires these adapters in:
- `app/Providers/PackageIntegration/InvitesIntegrationServiceProvider.php`
- `app/Integration/Invites/InviteIdentityGatewayAdapter.php`
- `app/Integration/Invites/InviteAttendanceGatewayAdapter.php`
- `app/Integration/Invites/InviteTelemetryEmitterAdapter.php`
- `app/Integration/Invites/InviteTargetReadAdapter.php`

The host route file is:
- `routes/api/packages/project_tenant_public_api_v1/invites.php`

---

## Runtime Settings

Runtime behavior is driven by the settings kernel and invite namespace payloads.

Important settings responsibilities:
- invite limits
- cooldown windows
- attendance policy resolution
- next-step behavior for the current attendance policy
- tenant-aware settings payload for the feed/settings endpoint

---

## Validation Commands

- `php artisan test tests/Feature/Invites/InvitesFlowTest.php`
- `php artisan test tests/Feature/Events/EventAttendanceControllerTest.php`
- `composer run architecture:guardrails`
- full `php artisan test` for milestone validation

---

## Known Limitations / Non-Goals

- The package does not own the auth mechanism or guard selection.
- The package does not own the event read model schema.
- The package does not own tenant resolution or `CheckTenantAccess`.
- The package does not guarantee backwards-compatible payloads beyond the current contract.
- Search, recommendation, and friendship graph semantics are out of scope here.
- Telemetry transport is host-owned; the package only emits through the contract.
