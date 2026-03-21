# Belluga Settings Kernel (`belluga/settings`)

Complete reference for the shared settings kernel used by Belluga packages.

## Purpose

This package centralizes settings schema registration, validation, merge semantics, and Mongo persistence.

It prevents each module from inventing its own settings payload shape or patch behavior.

## Scope

Owned by the package:
- schema registry
- schema validator
- conditional rules evaluator
- merge policy
- Mongo settings store
- tenant and landlord settings controllers
- tenant and landlord migrations

Not owned by the package:
- module-specific business logic
- host route registration
- host auth/tenant middleware decisions
- landlord on-behalf tenant resolution adapters

## Public API

The package is wired by host route files, not by a package route file.

Host route files in this repository:
- `routes/api/packages/project_tenant_package_admin_api_v1/settings.php`
- `routes/api/packages/project_landlord_admin_api_v1/settings.php`

### Tenant scope

Mounted under the host tenant settings prefix.

Endpoints:
- `GET /settings/schema`
- `GET /settings/values`
- `PATCH /settings/values/{namespace}`

Middleware:
- `auth:sanctum`
- `CheckTenantAccess`

### Landlord scope

Mounted under the host landlord settings prefix.

Endpoints:
- `GET /settings/schema`
- `GET /settings/values`
- `PATCH /settings/values/{namespace}`

Middleware:
- `auth:sanctum`

### Landlord on-behalf tenant scope

Mounted under the host landlord tenant prefix.

Endpoints:
- `GET /{tenant_slug}/settings/schema`
- `GET /{tenant_slug}/settings/values`
- `PATCH /{tenant_slug}/settings/values/{namespace}`

Middleware:
- `auth:sanctum`

Tenant switching is delegated to `TenantScopeContextContract`, which must be provided by the host when on-behalf flows are used.

## PATCH Contract

Endpoint: `PATCH /settings/values/{namespace}`

Payload must be a direct object/map. Namespace envelopes are rejected.

Rules:
- only provided keys are changed
- omitted keys remain untouched
- `null` clears only nullable fields
- `null` on a non-nullable field returns `422`
- arrays at the top level return `422`
- unknown field paths return `422`
- namespace not found in scope returns `404`
- missing ability returns `403`

## Schema Model

Namespaces are registered with `Belluga\Settings\Support\SettingsNamespaceDefinition`.

Field types supported by the kernel:
- `boolean`
- `integer`
- `number`
- `string`
- `array`
- `object`
- `date`
- `datetime`
- `mixed`

Fields may also declare:
- nullability
- defaults
- read-only flags
- deprecation flags
- display metadata
- grouping metadata
- conditional visibility/enabled rules

## Internal Components

Contracts:
- `SettingsRegistryContract`
- `SettingsStoreContract`
- `SettingsSchemaValidatorContract`
- `SettingsMergePolicyContract`
- `TenantScopeContextContract`

Runtime service:
- `SettingsKernelService`

Implementations:
- registry: `InMemorySettingsRegistry`
- validator: `SettingsSchemaValidator`
- merge: `NamespacePatchMergePolicy`
- store: `MongoSettingsStore`

Models:
- `SettingsDocument`
- `Models\Tenants\TenantSettings`
- `Models\Landlord\LandlordSettings`

## Host Integration

Host apps must:
1. Load the service provider.
2. Bind `TenantScopeContextContract` when using landlord on-behalf tenant flows.
3. Register namespaces through `SettingsRegistryContract`.
4. Keep all writes on the kernel patch contract.
5. Apply the correct abilities for each namespace.

In this repository, namespace registration lives in the host layer, including the core namespaces `map_ui`, `events`, and `push`.

## Migrations and Operations

Included migrations:
- tenant: `database/migrations/2026_02_26_000700_create_settings_collection.php`
- landlord: `database/migrations_landlord/2026_02_26_000710_create_landlord_settings_collection.php`

Both migrations create or normalize the single root document and fail fast on multi-document drift.

## Validation

Recommended checks:
- `php artisan test tests/Feature/Settings/SettingsKernelControllerTest.php`
- `php artisan test tests/Unit/Settings`
- `php artisan test`

## Non-Goals

- No module-specific settings business rules.
- No route registration inside the package.
- No implicit scope guessing.
