# Belluga Push Handler (Laravel Package)

Version 1.0

Push messaging runtime for the Belluga ecosystem.

## Purpose

This package provides the backend runtime for push messaging:
- push message CRUD and secure payload fetch
- device token registration and unregistration
- audience eligibility and plan policy hooks
- delivery metrics and action ingestion
- tenant push settings integration through the settings kernel

## Boundary

Owned by the package:
- controllers, services, validation, and migrations
- host-required contracts and fallback bindings
- push-specific runtime behavior

Owned by the host:
- route registration and route paths
- authentication middleware and tenant access checks
- external provider implementations such as FCM adapters
- plan/quota policy implementation when the host wants stricter rules

The package does not call `loadRoutesFrom(...)`.

## Configuration

Package config lives in `config/belluga_push_handler.php` and currently covers only runtime limits:
- `delivery_ttl_minutes`
- `fcm.max_batch_size`
- `fcm.max_ttl_days`

Route paths are defined in host route files, not in package config.

## Public Contracts

### Account routes
Host route file: `routes/api/packages/project_account_api_v1/push_handler.php`

Mounted under the account scope.

Endpoints:
- `GET /push/quota-check`
- `GET /push/messages`
- `POST /push/messages`
- `GET /push/messages/{push_message_id}`
- `PATCH /push/messages/{push_message_id}`
- `DELETE /push/messages/{push_message_id}`
- `GET /push/messages/{push_message_id}/data`
- `POST /push/messages/{push_message_id}/actions`
- `POST /push/messages/{push_message_id}/send`

Auth rules:
- CRUD and send endpoints require `auth:sanctum` + `account` + abilities.
- `/data` and `/actions` use `InitializeAccount` and may allow anonymous token contexts.

### Tenant routes
Host route file: `routes/api/packages/project_tenant_public_api_v1/push_handler.php`

Endpoints:
- `POST /push/register`
- `DELETE /push/unregister`
- `GET /push/messages`
- `POST /push/messages`
- `GET /push/messages/{push_message_id}`
- `PATCH /push/messages/{push_message_id}`
- `DELETE /push/messages/{push_message_id}`
- `GET /push/messages/{push_message_id}/data`
- `POST /push/messages/{push_message_id}/actions`
- `POST /push/messages/{push_message_id}/send`
- `GET /settings/push`
- `PATCH /settings/push`
- `POST /settings/push/enable`
- `POST /settings/push/disable`
- `GET /settings/push/route_types`
- `PATCH /settings/push/route_types`
- `DELETE /settings/push/route_types`
- `GET /settings/push/message_types`
- `PATCH /settings/push/message_types`
- `DELETE /settings/push/message_types`
- `GET /settings/push/status`
- `GET /settings/firebase`
- `PATCH /settings/firebase`
- `GET /push/credentials`
- `PUT /push/credentials`

Auth rules:
- Tenant routes use `auth:sanctum` + `CheckTenantAccess`.
- Push message and settings actions require the relevant abilities.

### Landlord routes
Host route file: `routes/api/packages/project_landlord_admin_api_v1/push_handler.php`

Endpoints:
- `GET /{tenant_slug}/settings/push`
- `PATCH /{tenant_slug}/settings/push`
- `GET /{tenant_slug}/settings/firebase`
- `PATCH /{tenant_slug}/settings/firebase`

Auth rules:
- `auth:sanctum`
- `abilities:push-settings:update`

## Data Model

Mongo collections owned by the package:
- `push_messages`
- `push_message_actions`
- `push_credentials`
- `push_delivery_logs`

Migration location:
- `packages/belluga/belluga_push_handler/database/migrations`

## Host Integration

Provider bindings currently resolve:
- `PushPlanPolicyContract`
- `PushAudienceEligibilityContract`
- `FcmClientContract`

Host-required bindings are fail-fast placeholders for:
- `PushAccountContextContract`
- `PushTenantContextContract`
- `PushUserGatewayContract`
- `PushTelemetryEmitterContract`
- `PushSettingsStoreContract`
- `PushSettingsMutationContract`

The package consumes the settings kernel namespaces `push` and `firebase`, but it does not own the core settings kernel itself.

## Request and Response Notes

- `/settings/push` manages push-only fields.
- `/settings/firebase` manages FCM credentials/settings.
- `route_types` and `message_types` endpoints accept raw arrays, not an envelope.
- `DELETE` operations for route/message types accept `{ "keys": [...] }`.
- `delivery_deadline_at` caps delivery expiration; `expires_at` is derived server-side.

## Multitenancy

Current classification: `tenant` for package-owned collections/migrations.

## Validation

Recommended checks:
- package decoupling assertion for host bindings
- `composer run architecture:guardrails`
- targeted push-handler tests
- full Laravel suite

## Non-Goals

- No route-path config file inside the package.
- No route registration from the service provider.
- No external FCM implementation beyond the host-bound client contract.
