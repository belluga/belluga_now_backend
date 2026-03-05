# Belluga Settings Kernel (`belluga/settings`)

Complete reference for developers working on the settings kernel.

This package is the canonical settings runtime for the Belluga ecosystem. It centralizes how modules define settings, how clients discover schema, and how values are read/updated with strict semantics.

Use this README as the primary technical reference for implementation and maintenance.

---

## What This Package Solves

Before this package, each module tended to own ad-hoc settings persistence and custom endpoints. This caused drift in:

- payload shape
- patch behavior
- validation rules
- scope handling (`tenant` vs `landlord`)
- UI metadata consistency

`belluga/settings` standardizes all of that.

---

## Core Model (Important)

### 1) Scoped Settings

Every namespace is explicitly scoped:

- `tenant`
- `landlord`

### 2) Namespace-Based Organization

A namespace is an immutable technical key (snake_case), for example:

- `map_ui`
- `events`
- `push`

Each namespace has:

- schema (field types + metadata)
- optional ability guard
- values under one shared root document per scope DB

### 3) Singleton Document Per Scope Database

Settings are stored in Mongo `settings` collection with fixed root id:

- `_id = settings_root`

Rules:

- exactly one settings document per scope DB
- migration enforces this with Mongo `collMod` validator
- migration fails fast when multiple docs exist

---

## Package Responsibilities

This package provides:

- contracts
- schema registry
- schema validator
- conditional rules model + evaluator
- merge policy
- Mongo settings store
- generic HTTP controllers/routes
- tenant + landlord migrations

What it does **not** do:

- module-specific business logic
- module-specific endpoint wrappers
- tenant context resolution for landlord on-behalf (host must provide adapter)

---

## Public API (Canonical)

Routes are loaded from `packages/belluga/belluga_settings/routes/settings.php`.

### Tenant scope

Base prefix (default): `api/v1/settings`

- `GET /schema`
- `GET /values`
- `PATCH /values/{namespace}`

Middleware:

- `tenant`
- `auth:sanctum`
- `CheckTenantAccess`

### Landlord scope

Base prefix (default): `admin/api/v1/settings`

- `GET /schema`
- `GET /values`
- `PATCH /values/{namespace}`

Middleware:

- `landlord`
- `auth:sanctum`

### Landlord on-behalf tenant scope

Base prefix (default): `admin/api/v1/{tenant_slug}/settings`

- `GET /schema`
- `GET /values`
- `PATCH /values/{namespace}`

Middleware:

- `landlord`
- `auth:sanctum`

Tenant switching is delegated to `TenantScopeContextContract` (host binding required).

---

## PATCH Contract (Locked)

Endpoint: `PATCH /settings/values/{namespace}`

Payload must be a direct object/map (no envelope):

```json
{
  "max_ttl_days": 21,
  "throttles.per_minute": 120
}
```

Rules:

- only payload-present keys are changed
- omitted keys remain unchanged
- `null` means explicit clear only for nullable fields
- `null` on non-nullable field returns `422`
- list payload (JSON array) returns `422`
- unknown field path returns `422`
- namespace envelope style is rejected

Typical errors:

- `404` namespace not found in scope
- `403` token cannot access namespace ability
- `422` payload/field/type/nullability invalid

---

## Schema Definition Model

Namespace definitions are created with:

- `Belluga\Settings\Support\SettingsNamespaceDefinition`

Minimum required constructor data:

- `namespace`
- `scope`
- `label`
- `fields`

Per-field supports:

- `type`: `boolean|integer|number|string|array|object|date|datetime|mixed`
- `nullable`
- `default`
- `readonly`
- `deprecated`
- `order`
- `options`
- display metadata
- hierarchy metadata (`group`, `subgroup`, labels/i18n/icons)
- conditional metadata (`visible_if`, `enabled_if`)

Schema output includes both:

- `fields` (flat)
- `nodes` (tree) for dynamic UI rendering

---

## Conditional Rules DSL

The DSL is OR-of-AND:

- expression has `groups[]` (OR)
- each group has `rules[]` (AND)

Rule shape:

- `field_id`
- `operator`
- `value`

Supported operators:

- `equals`
- `not_equals`
- `in`
- `not_in`
- `exists`
- `gt`
- `gte`
- `lt`
- `lte`

Hard limits:

- max groups: 10
- max rules per group: 10
- max total rules: 50
- max condition payload size: 16KB

Comparable operators (`gt/gte/lt/lte`) are only valid for comparable field types (`integer`, `number`, `date`, `datetime`).

---

## Key Internal Components

### Contracts

- `SettingsRegistryContract`
- `SettingsStoreContract`
- `SettingsSchemaValidatorContract`
- `SettingsMergePolicyContract`
- `TenantScopeContextContract`

### Runtime service

- `SettingsKernelService`

### Implementations

- registry: `InMemorySettingsRegistry`
- validator: `SettingsSchemaValidator`
- merge: `NamespacePatchMergePolicy`
- store: `MongoSettingsStore`

### Models

- base: `SettingsDocument`
- tenant: `Models\Tenants\TenantSettings` (`UsesTenantConnection`)
- landlord: `Models\Landlord\LandlordSettings` (`UsesLandlordConnection`)

---

## Host App Integration Checklist

When integrating this package in a host app:

1. Ensure provider is loaded.
2. Bind `TenantScopeContextContract` in host container.
3. Register namespaces from host/modules using `SettingsRegistryContract`.
4. Use per-namespace abilities (token-based).
5. Keep all settings writes through kernel patch semantics.

### Required host binding

`TenantScopeContextContract` must be bound by host app.

Repository example:

- `App\Integration\Settings\TenantScopeContextAdapter`

### Namespace registration locations in this repository

- Core namespaces (`map_ui`, `events`): `AppServiceProvider`
- Push namespace (`push`): `PushHandlerServiceProvider`

---

## Migrations and Operations

Included migrations:

- tenant: `database/migrations/2026_02_26_000700_create_settings_collection.php`
- landlord: `database/migrations_landlord/2026_02_26_000710_create_landlord_settings_collection.php`

Both migrations:

- create collection if missing
- enforce root singleton
- migrate legacy single-doc id to `settings_root`
- fail if multi-doc state is found
- apply strict Mongo validator (`_id == settings_root`)

### Tenant migration execution (Spatie flow)

```bash
php artisan tenants:artisan "migrate --database=tenant --path=packages/belluga/belluga_settings/database/migrations"
```

### Landlord migration execution

```bash
php artisan migrate --database=landlord --path=packages/belluga/belluga_settings/database/migrations_landlord
```

### Multitenancy Classification (Required)

Before adding migration files, classify scope explicitly:
- `tenant`
- `landlord`
- `mixed`

Current classification for `belluga_settings`:
- `mixed` (tenant + landlord).

Rules for this package:
- Tenant migrations stay in `packages/belluga/belluga_settings/database/migrations` and must be listed in `config/multitenancy.php` `tenant_migration_paths`.
- Landlord migrations stay in `packages/belluga/belluga_settings/database/migrations_landlord` and run only with landlord connection/path.
- Never cross-run migration directories between tenant and landlord flows.
- Namespace scope (`tenant` vs `landlord`) must remain consistent with where data is stored and migrated.

---

## Example: Register a Namespace

```php
$registry->register(new SettingsNamespaceDefinition(
    namespace: 'push',
    scope: 'tenant',
    label: 'Push',
    groupLabel: 'Notifications',
    ability: 'push-settings:update',
    fields: [
        'enabled' => [
            'type' => 'boolean',
            'nullable' => false,
            'label' => 'Enabled',
            'order' => 10,
        ],
        'max_ttl_days' => [
            'type' => 'integer',
            'nullable' => false,
            'label' => 'Max TTL Days',
            'order' => 20,
        ],
    ],
));
```

---

## Example: Canonical Patch Request

`PATCH /api/v1/settings/values/push`

```json
{
  "enabled": true,
  "max_ttl_days": 21
}
```

Response:

```json
{
  "data": {
    "enabled": true,
    "max_ttl_days": 21
  }
}
```

---

## Testing Guidance

Primary tests in this repository:

- `tests/Feature/Settings/SettingsKernelControllerTest.php`
- `tests/Unit/Settings/SettingsPackageBindingsTest.php`
- `tests/Unit/Settings/SettingsRegistryTest.php`
- `tests/Unit/Settings/SettingsSchemaValidatorTest.php`
- `tests/Unit/Settings/SettingsNamespaceDefinitionTest.php`
- `tests/Unit/Settings/ConditionExpressionEvaluatorTest.php`

Recommended sequence:

```bash
php artisan test tests/Feature/Settings/SettingsKernelControllerTest.php
php artisan test tests/Unit/Settings
php artisan test
```

---

## Current Repository Status (Important Context)

As of current implementation in this repo:

- settings kernel foundation is complete
- registered tenant namespaces include `map_ui`, `events`, `push`
- push migration Phase #1 already uses kernel for:
  - `push.enabled`
  - `push.throttles`
  - `push.max_ttl_days`
- push migration Phase #2 is still pending for:
  - `firebase`
  - `telemetry`
  - `push.message_routes`
  - `push.message_types`

---

## Troubleshooting

### `404 Settings namespace not found`

- check namespace registration exists for the requested scope
- check namespace key is snake_case and exact

### `403 Not authorized for this settings namespace`

- token is missing required ability defined in namespace

### `422` on patch

- payload is array/list instead of object
- unknown field path
- wrong field type
- null provided to non-nullable field
- envelope payload used instead of direct map

### Migration singleton error

- more than one document exists in `settings` for that scope DB
- cleanup required before migration can proceed

### Landlord on-behalf failing

- host did not bind `TenantScopeContextContract`
- tenant slug resolution/context switch issue

---

## Quick Start

When working on settings, follow this sequence:

1. Read this README completely.
2. Confirm scope (`tenant` or `landlord`).
3. Confirm namespace owner and ability.
4. Confirm field schema (types/nullability/defaults/conditions).
5. Implement writes only through kernel patch flow.
6. Validate with targeted settings tests and then full Laravel suite.

This sequence prevents nearly all settings regressions seen in previous iterations.
