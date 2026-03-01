# Belluga Ticketing Package (`belluga/ticketing`)

This package owns ticketing-domain runtime concerns for Events integration.

## Ownership Boundary
- `belluga_events` owns event/occurrence/publication/location lifecycle.
- `belluga_ticketing` owns inventory, holds, queue admission, order snapshots, ticket-unit lifecycle, and admission validation.
- Payment provider mechanics remain Checkout-owned through contract integration (`CheckoutOrchestratorContract`).

## Host Integration Contracts
Host app must bind:
- `OccurrenceReadContract`
- `OccurrencePublicationContract`
- `EventTemplateReadContract`
- `CheckoutOrchestratorContract`
- `TicketingPolicyContract`

Current host adapters live in [`app/Integration/Ticketing`](../../../../app/Integration/Ticketing).

## Runtime Identity Model
Canonical keys:
- `event_id` (parent)
- `occurrence_id` (runtime operational scope)
- `ticket_product_id`
- `ticket_order_id` / `ticket_order_item_id`
- `ticket_unit_id` (admission/check-in identity)

Admission/check-in validates by `ticket_unit_id` (or admission code hash), never by `event_id` alone.

## Settings Namespaces (Runtime Keys)
Registered tenant namespaces:
- `ticketing_core` (`enabled`, `identity_mode`)
- `ticketing_hold_queue` (`policy=auto|off`, `default_hold_minutes`, `max_per_principal`)
- `ticketing_seating` (VNext toggle)
- `ticketing_validation`
- `ticketing_security`
- `ticketing_lifecycle`
- `checkout_core` (`mode=free|paid`)
- `checkout_ticketing` (`enabled`)
- `participation_presence`
- `participation_proofs`

## API Surface (v1)
- `GET /api/v1/events/{event_id}/occurrences/{occurrence_id}/offer`
- `POST /api/v1/events/{event_id}/occurrences/{occurrence_id}/admission`
- `POST /api/v1/admission/tokens/refresh`
- `GET /api/v1/checkout/cart?hold_token=...`
- `POST /api/v1/checkout/confirm`
- `POST /api/v1/events/{event_id}/occurrences/{occurrence_id}/validation`
- Admin:
  - `GET /admin/api/v1/events/{event_id}/occurrences/{occurrence_id}/ticket_products`
  - `POST /admin/api/v1/events/{event_id}/occurrences/{occurrence_id}/ticket_products`

## Hold/Queue/Capacity Rules
- Hold protection is always applied for limited inventory.
- Queue policy is `auto|off` (default `auto`).
- Unlimited inventory returns `not_applicable` admission state.
- No cart/checkout mutation is accepted without valid active `hold_token`.
- `free` flow uses the same anti-oversell hold/reservation pipeline as paid mode.

## Transaction and Idempotency Guarantees
- Critical capacity/lifecycle paths run via tenant transaction runner (`TenantTransactionRunner`).
- Runtime fails fast with `transaction_unavailable` when transaction support is missing.
- Mutation flows use idempotency keys (`ticket_holds`, `ticket_orders`, `ticket_checkin_logs`).

## Immutable Financial/Snapshot Contracts
- `checkout_payload_snapshot` is frozen at checkout handoff.
- `financial_snapshot` is frozen at confirmation.
- Snapshot hash is deterministic (`snapshot_hash`) and replay-safe.

## Outbox and Async Side Effects
- Outbox events are persisted in `ticket_outbox_events`.
- Scheduler job `ProcessTicketOutboxJob` processes pending events.
- Package emits deterministic topics for hold/order/unit lifecycle and participation presence events.

## Event Template Integration
Event creation supports `template_id` lookup through `EventTemplateReadContract`:
- server applies template defaults,
- hidden/disabled paths are protected from payload override,
- audit metadata is persisted under `event.ticketing.template.{template_id,version}`.

## Tenant Collections (MIG-01)
- `ticket_products`
- `ticket_event_templates`
- `ticket_inventory_states`
- `ticket_holds`
- `ticket_queue_entries`
- `ticket_orders`
- `ticket_order_items`
- `ticket_units`
- `ticket_checkin_logs`
- `ticket_outbox_events`

## Deferred to VNext
- Seat-map capability (`ticketing_seating`)
- Waitlist/presales capability
- Advanced checkout webhook/reconciliation ownership flows (checkout package stream)
