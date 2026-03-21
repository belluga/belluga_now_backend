# belluga_favorites

Version 1.0

Tenant-scoped favorite edges and snapshot-backed discovery projections.

## Scope

- Owns the favorites command/query services and snapshot projection service.
- Persists the `favorite_edges`, `favoritable_snapshots`, and `favoritable_account_profile_snapshots` collections.
- Does not own authentication implementation, tenant resolution, or route registration.

## Public Contracts

Host route file: `routes/api/packages/project_tenant_public_api_v1/favorites.php`

Middleware owned by the host: `auth:sanctum` + `CheckTenantAccess`

Endpoints:
- `GET /favorites` returns `{ items: [], has_more: false }` for unauthenticated or anonymous identities.
- `POST /favorites` creates or refreshes a favorite edge.
- `DELETE /favorites` removes a favorite edge.

Request contract for mutations:
- `target_id` is required.
- `registry_key` is optional and must be snake_case when present.
- `target_type` is optional.

Response contract for mutations:
- The selector payload returned by the command service is echoed back.
- `is_favorite` is `true` on `POST` and `false` on `DELETE`.

## Authentication Boundary

- Mutations require an authenticated user context.
- The package reads identity only from `request()->user()`.
- The package does not know how the host authenticates the user.
- Anonymous identities (`identity_state=anonymous`) cannot mutate favorites.

## Data Model and Migrations

Tenant scope only.

Collections:
- `favorite_edges`
- `favoritable_snapshots`
- `favoritable_account_profile_snapshots`

Key invariants:
- favorite edges are unique by owner, registry, target type, and target id.
- favoritable snapshots are unique by registry, target type, and target id.
- the account-profile snapshot collection is used for discovery-facing snapshot projections.

Migration location:
- `packages/belluga/belluga_favorites/database/migrations`

## Host Integration

- The package service provider binds the registry and services only.
- The host app owns the route file and middleware chain.
- The host app must supply the authenticated user context and tenant access checks.

## Validation

Recommended checks:
- `php artisan test tests/Feature/Favorites/FavoritesControllerTest.php`
- `php artisan test`

## Non-Goals

- No auth provider implementation.
- No tenant resolution strategy.
- No route ownership inside the package.
- No anonymous mutation support.
