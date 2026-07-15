# belluga/contact-channels

Framework-neutral contact-channel definitions and collection normalization for
Belluga. It is a local shared-kernel Composer library: it has no Laravel,
Eloquent, MongoDB, tenant, routing, controller, or `App\\` dependency.

## Scope

- Closed first-delivery registry for `email` and `whatsapp`.
- Canonical labels/icons, values, metadata, capability descriptors, and launch
  URI resolution.
- Collection invariants: a maximum of 20 channels, server-owned stable channel
  ids, unique new-channel `draft_key` values, unique CTA ids within one
  WhatsApp channel, and titles when a type repeats.
- Server-side normalization that maps a request-local draft key to a generated
  persistent channel id during one write transaction.

## Non-goals

The package does not persist Account Profiles, resolve tenants, look up mirror
sources, authorize requests, register routes, or render a UI. Those concerns
remain with the Laravel and Flutter hosts.

## Definitions

`ContactChannelDefinitionRegistry::withFirstDeliveryDefinitions()` registers:

- `email`: public card and `mailto:` direct launch; metadata and bubble
  selection are unsupported.
- `whatsapp`: public card, direct `https://wa.me/<digits>` launch, bubble
  eligibility, and channel-owned `initial_messages` presets. Presets belong to
  each valid WhatsApp channel; selecting a bubble does not affect other cards.

The stored WhatsApp value remains trimmed user input. Launch resolution derives
the target URI at runtime and fails closed when the value is not safely
resolvable.

## Write contract

Pass the raw incoming collection and the profile's already-persisted collection
to `ContactChannelCollectionNormalizer::normalizeForWrite()`.

- Existing records identify themselves with an id that already belongs to that
  profile. The id and type are immutable; unknown ids are rejected.
- New records omit `id`, provide a unique `draft_key`, and receive a generated
  opaque id. `draftKeyToChannelId` maps the request-local key to that id.
- A caller must explicitly clear or reselect a bubble pointer when it removes
  its selected channel. The package validates the channel collection; the host
  owns pointer/mirror persistence and atomicity.

## Data and host integration

This package owns no migrations (`none` data scope) and has no authentication
or authorization behavior. The Laravel host binds the registry, identifier
generator, and normalizer in a package-integration provider, converts request
or persistence data to plain arrays, applies Laravel request structure rules,
and translates `ContactChannelValidationException` to its API validation shape.

## Fixture and validation

`fixtures/contact_channels.v1.json` is the package-local versioned vector
fixture. Its shape is `version`, per-channel `capabilities` and `vectors`, plus
`collection_invariants`; the mandatory root equality gate keeps it byte-for-byte
equal to the canonical Foundation corpus.

Run focused checks from `laravel-app`:

```bash
php artisan test --testsuite=Package-ContactChannels
php artisan test tests/Feature/AccountProfiles/AccountProfilesControllerTest.php
composer run architecture:guardrails
```
