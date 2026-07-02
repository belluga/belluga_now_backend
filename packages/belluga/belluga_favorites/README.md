# belluga_favorites

Version 1.0

Tenant-scoped favorite edges plus bounded favorites query/read services.

## Scope

- Owns the favorites command/query services.
- Persists the `favorite_edges` collection.
- Does not read or rebuild snapshot collections for the current runtime contract.
- Does not own authentication implementation, tenant resolution, or route registration.

## Public Contracts

Host route file: `routes/api/packages/project_tenant_public_api_v1/favorites.php`

Middleware owned by the host: `auth:sanctum` + `CheckTenantAccess`

Endpoints:
- `GET /favorites` returns `{ items: [], has_more: false }` only when there is no authenticated user context.
- For `registry_key=account_profile` / `target_type=account_profile`, the current read path is bounded direct-read and no longer depends on `favoritable_account_profile_snapshots`.
- Current account-profile `/favorites` paging is bounded to `page_size <= 10`.
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

- Reads and mutations require a host-authenticated user context resolved through `request()->user()`.
- The package reads identity only from `request()->user()`.
- The package does not know how the host authenticates the user.
- Whether an authenticated identity is anonymous or registered is host/runtime behavior; the current favorites controller does not apply an additional `identity_state` veto inside the package.

## Data Model and Migrations

Tenant scope only.

Collections:
- `favorite_edges`
- legacy tenants may still have `favoritable_snapshots`
- legacy tenants may still have `favoritable_account_profile_snapshots`

Key invariants:
- favorite edges are unique by owner, registry, target type, and target id.
- account-profile favorites ordering/navigation now comes from bounded direct reads over favorite edges, active account profiles, and canonical event-occurrence associations.
- runtime favorites services no longer read, rebuild, or route through snapshot builders for the current account-profile contract.

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
- No package-local identity-state policy beyond the host-authenticated user context.
