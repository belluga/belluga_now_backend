# Belluga Deep Links (`belluga/deep-links`)

Canonical package for deep link and deferred deep link governance.

## Scope

Owned by this package:
- App Links / Universal Links association payload generation (`/.well-known/assetlinks.json`, `/.well-known/apple-app-site-association`);
- `settings.app_links` namespace registration and patch-guard validation;
- web promotion/open-app redirect resolution (`/open-app`);
- deferred first-open resolver contract (`/api/v1/deep-links/deferred/resolve`).

Not owned by this package:
- host route composition and middleware boundaries;
- host adapter bindings for tenant/domain/settings context;
- app invite business mutations.

## Host Contracts

The host must bind:
- `Belluga\DeepLinks\Contracts\AppLinksIdentifierGatewayContract`
- `Belluga\DeepLinks\Contracts\AppLinksSettingsSourceContract`

These adapters provide tenant/domain-specific context while keeping package logic decoupled from host models.

## Public Runtime Surfaces

Typical host routes:
- `GET /.well-known/assetlinks.json`
- `GET /.well-known/apple-app-site-association`
- `GET /open-app`
- `POST /api/v1/deep-links/deferred/resolve`

## Notes

- Android deferred flow is MVP-first; iOS deferred capture can reuse the same resolver contract later.
- iOS/Android store URLs are tenant-configurable in `settings.app_links` (`android.store_url`, `ios.store_url`).
