# belluga_discovery_filters

Local Laravel package for Belluga discovery filter grammar, entity registries, and catalog selection repair.

## Scope

- Owns canonical filter vocabulary: `surface`, `target`, `entity`, `type`, primary selection mode, and taxonomy groups.
- Owns provider registration contracts for entity/type/taxonomy catalog discovery.
- Owns stale selection repair against an admin-defined filter catalog.

## Non-Scope

- Does not execute Map POI, Agenda/EventOccurrence, Account Profile Discovery, or Static Asset queries.
- Does not own tenant-admin pages or public result rendering.
- Does not replace read-model-specific adapters.

Host modules register providers and compile repaired selections into their own query contracts.
