# Belluga Ticketing Package (`belluga/ticketing`)

Canonical reference for the ticketing runtime used by Events.

This README is the source of truth for:
- purpose and scope
- domain invariants
- persistence model
- public contracts
- host integration requirements
- validation commands
- non-goals and deferred capabilities

If a client or host adapter needs to integrate with ticketing, use this document first.

---

## Purpose and Scope

This package owns ticketing-domain runtime concerns for Events integration.
It handles inventory, holds, queue admission, order snapshots, ticket-unit lifecycle, transfer/reissue, realtime streams, and admission validation.

It does not own event lifecycle, payment provider mechanics, or host auth strategy.

---

## Domain Concepts and Invariants

### Ownership boundary
- `belluga_events` owns event/occurrence/publication/location lifecycle.
- `belluga_ticketing` owns inventory, holds, queue admission, order snapshots, ticket-unit lifecycle, and admission validation.
- Payment provider mechanics remain Checkout-owned through `CheckoutOrchestratorContract`.

### Canonical identity model
- `event_id` is the parent scope.
- `occurrence_id` is the runtime operational scope.
- `ticket_product_id` identifies the sellable product.
- `ticket_order_id` / `ticket_order_item_id` identify order snapshots.
- `ticket_unit_id` is the canonical admission/check-in identity.

Admission/check-in validates by `ticket_unit_id` (or admission code hash), never by `event_id` alone.

### Terminology boundary
- `event_parties` belong to the Events domain and describe event composition.
- Attendees/students belong to Ticketing/Participation and describe entitlement or access identity.
- Ticketing does not persist or resolve `event_parties` for eligibility.
- Events does not use `event_parties` as attendee/student entitlement model.

### Runtime invariants
- Hold protection is always applied for limited inventory.
- Queue policy is `auto|off` with `auto` as the default.
- Unlimited inventory returns `not_applicable` admission state.
- No cart/checkout mutation is accepted without a valid active `hold_token`.
- `free` flow uses the same anti-oversell hold/reservation pipeline as paid mode.
- `checkout_payload_snapshot` is frozen at checkout handoff.
- `financial_snapshot` is frozen at confirmation.
- Snapshot hash is deterministic and replay-safe.

---

## Data Model and Migrations

Tenant collections owned by the package:
- `ticket_products`
- `ticket_promotions`
- `ticket_promotion_redemptions`
- `ticket_event_templates`
- `ticket_inventory_states`
- `ticket_holds`
- `ticket_queue_entries`
- `ticket_orders`
- `ticket_order_items`
- `ticket_units`
- `ticket_checkin_logs`
- `ticket_unit_audit_events`
- `ticket_outbox_events`

Migration scope:
- tenant only
- package migrations load from `packages/belluga/belluga_ticketing/database/migrations`

---

## Public Contracts

Host route files:
- `routes/api/packages/project_tenant_public_api_v1/ticketing.php`
- `routes/api/packages/project_tenant_package_admin_api_v1/ticketing.php`

### Public read routes
- `GET /api/v1/events/{event_ref}/occurrences/{occurrence_ref}/offer`
- `GET /api/v1/occurrences/{occurrence_ref}/offer`
- `GET /api/v1/ticketing/streams/offer/{scope_type}/{scope_id}`

### Authenticated tenant routes
- `POST /api/v1/events/{event_ref}/occurrences/{occurrence_ref}/admission`
- `POST /api/v1/occurrences/{occurrence_ref}/admission`
- `POST /api/v1/admission/tokens/refresh`
- `GET /api/v1/checkout/cart?hold_token=...`
- `POST /api/v1/checkout/confirm`
- `POST /api/v1/events/{event_id}/occurrences/{occurrence_id}/validation`
- `POST /api/v1/events/{event_id}/occurrences/{occurrence_id}/ticket_units/{ticket_unit_id}/transfer`
- `POST /api/v1/events/{event_id}/occurrences/{occurrence_id}/ticket_units/{ticket_unit_id}/reissue`
- `GET /api/v1/ticketing/streams/queue/{scope_type}/{scope_id}`
- `GET /api/v1/ticketing/streams/hold/{hold_id}`

### Admin routes
- `GET /admin/api/v1/events/{event_id}/occurrences/{occurrence_id}/ticket_products`
- `POST /admin/api/v1/events/{event_id}/occurrences/{occurrence_id}/ticket_products`
- `GET /admin/api/v1/events/{event_id}/occurrences/{occurrence_id}/ticket_promotions`
- `POST /admin/api/v1/events/{event_id}/occurrences/{occurrence_id}/ticket_promotions`

### Request and response notes
- Public offer routes are open.
- Authenticated tenant routes use `auth:sanctum` + `CheckTenantAccess`.
- Admin routes use the host ability gates defined in the route files.
- Public read routes use `event_ref` / `occurrence_ref`.
- `ticketing/streams/*` are realtime read surfaces only; mutations remain on the authenticated routes.

---

## Authentication and Authorization Boundary

- The package does not own authentication implementation.
- The host must provide the middleware and guard stack.
- Public offer routes can be read without tenant auth.
- Mutating tenant routes require `auth:sanctum` and `CheckTenantAccess`.
- Admin ticket routes require the relevant host abilities in addition to tenant auth.
- The package fails fast if required host contracts are missing.

---

## Host Integration Steps

1. Register `TicketingServiceProvider`.
2. Bind the host contracts:
   - `OccurrenceReadContract`
   - `OccurrencePublicationContract`
   - `EventTemplateReadContract`
   - `CheckoutOrchestratorContract`
   - `TicketingPolicyContract`
   - `TicketingSettingsStoreContract`
3. Mount the host route files for public tenant ticketing and tenant package-admin ticketing.
4. Keep the host adapters in `app/Integration/Ticketing`.
5. Ensure tenant settings expose the runtime namespaces:
   - `ticketing_core`
   - `ticketing_hold_queue`
   - `ticketing_validation`
   - `ticketing_security`
   - `ticketing_lifecycle`
   - `ticketing_promotions`
   - `checkout_core`
   - `checkout_ticketing`
   - `participation_presence`
   - `participation_proofs`
6. Let the service provider load tenant migrations from the package migration directory.

---

## Validation Commands

- `php artisan test tests/Feature/Ticketing`
- `php artisan test tests/Feature/Events/EventCrudControllerTest.php`
- `php artisan test tests/Feature/Events/AgendaAndEventsControllerTest.php`
- `php artisan test tests/Unit/Events/EventsPackageBindingsTest.php`
- `php artisan test`

---

## Known Limitations and Non-Goals

- Seat-map capability (`ticketing_seating`) remains deferred to VNext.
- Waitlist/presales capability remains deferred to VNext.
- Advanced checkout webhook/reconciliation ownership flows remain outside the package and stay Checkout-owned.
- The package does not register routes itself; route ownership stays with the host.
