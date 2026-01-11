# Belluga Push Handler (Laravel Package)

**Version:** 1.0  
**Status:** Foundation package (in-repo until extraction).

## Purpose
This package provides the Laravel backend capabilities for push messaging and telemetry delivery:
- Push message CRUD and secure payload fetch
- Device token registration/unregistration
- Audience evaluation and delivery lifecycle metrics
- Tenant push settings management (including routes + types)

The host application retains control of **route paths** via config while the package **registers** the routes and provides the controllers, services, and migrations.

## Package Location
`laravel-app/packages/belluga/belluga_push_handler`

## Architecture Snapshot
**Responsibilities owned by the package**
- Controllers, services, and validation for push messaging.
- Device registration and token rotation handling.
- Audience checks, metrics aggregation, and action ingestion.
- Migrations and MongoDB collections for push entities.
- Route registration with configurable host paths.

**Responsibilities owned by the host app**
- Route path strings (prefixes and endpoints).
- Tenant settings hub (push extends this, host owns the base).
- Account plans/quotas (via the policy contract).
- Any external providers (FCM client implementation).

## Installation (In-Repo)
1. Ensure the package is autoloaded by the Laravel app (already handled in this repo).
2. Register the provider (already registered in this repo):
   - `Belluga\PushHandler\PushHandlerServiceProvider`
3. Ensure host config is present:
   - `laravel-app/config/belluga_push_handler.php`

## Configuration

### Route Paths (Host Controlled)
Host app owns route path strings. Package reads them from:
- `config('belluga_push_handler.routes')`

Default config (can be overridden in the host app):
```php
return [
    'routes' => [
        'account' => [
            'prefix' => 'api/v1/accounts/{account_slug}',
            'messages_prefix' => 'push/messages',
        ],
        'tenant' => [
            'prefix' => 'api/v1',
            'register' => 'push/register',
            'unregister' => 'push/unregister',
            'settings_prefix' => 'settings',
            'settings_push' => 'push',
        ],
        'landlord' => [
            'prefix' => 'admin/api/v1',
            'tenant_settings_path' => '{tenant_slug}/settings/push',
        ],
    ],
];
```

### Policy Hook (Plan Quotas)
The package binds a default allow-all policy if none is provided:
```php
Belluga\PushHandler\Contracts\PushPlanPolicyContract
```
Host apps can bind their own implementation to enforce plan limits later.

**Example binding**
```php
use Belluga\PushHandler\Contracts\PushPlanPolicyContract;
use App\Services\PushPlanPolicy;

app()->bind(PushPlanPolicyContract::class, PushPlanPolicy::class);
```

## Routes

### Account Routes (Partner / Account Users)
Mounted under:
```
{account.prefix}/{account.messages_prefix}
```
Endpoints:
- `GET /` — list messages
- `POST /` — create message
- `GET /{push_message_id}` — message config
- `PATCH /{push_message_id}` — update message
- `DELETE /{push_message_id}` — delete/archived message
- `GET /{push_message_id}/data` — fetch message payload
- `POST /{push_message_id}/actions` — record recipient action

Auth/middleware:
- CRUD: `auth:sanctum` + `account` + abilities
- `/data` + `/actions`: `InitializeAccount` (anonymous token allowed)

### Tenant Routes
Mounted under:
```
{tenant.prefix}
```
Endpoints:
- `POST /{tenant.register}` — device token register
- `DELETE /{tenant.unregister}` — device token unregister
- `GET /{tenant.settings_prefix}/{tenant.settings_push}` — tenant push settings
- `PATCH /{tenant.settings_prefix}/{tenant.settings_push}` — update tenant push settings
- `POST /{tenant.settings_prefix}/{tenant.settings_push}/enable` — enable push after config
- `POST /{tenant.settings_prefix}/{tenant.settings_push}/disable` — disable push
- `GET /{tenant.settings_prefix}/firebase` — firebase settings
- `PATCH /{tenant.settings_prefix}/firebase` — update firebase settings
- `GET /{tenant.settings_prefix}/telemetry` — list telemetry integrations
- `POST /{tenant.settings_prefix}/telemetry` — add/update telemetry integration
- `DELETE /{tenant.settings_prefix}/telemetry/{type}` — remove telemetry integration
- `GET /{tenant.settings_prefix}/{tenant.settings_push}/route_types` — list push route types
- `PATCH /{tenant.settings_prefix}/{tenant.settings_push}/route_types` — replace push route types
- `GET /{tenant.settings_prefix}/{tenant.settings_push}/message_types` — list push message types
- `PATCH /{tenant.settings_prefix}/{tenant.settings_push}/message_types` — replace push message types

Auth/middleware:
- `auth:sanctum` + `CheckTenantAccess` (+ `abilities:push-settings:update` on settings)

### Push Setup
Use the tenant endpoints below to configure and enable push in order.

1) **Create push credentials** (tenant scope)
```json
{
  "project_id": "project-id",
  "client_email": "client@example.org",
  "private_key": "-----BEGIN PRIVATE KEY-----\\n...\\n-----END PRIVATE KEY-----\\n"
}
```

2) **Set firebase settings**
```json
{
  "firebase": {
    "apiKey": "AIzaSy...example",
    "appId": "1:XXXXXXXXXXXX:android:f73db77742a1b07f2302f7",
    "projectId": "string",
    "messagingSenderId": "XXXXXXXXXXXX",
    "storageBucket": "string.firebasestorage.app"
  }
}
```

3) **Set push settings**
```json
{
  "push": {
    "max_ttl_days": 30,
    "throttles": {
      "max_per_minute": 60,
      "max_per_hour": 600
    }
  }
}
```

4) **Define route types**
```json
[
  { "key": "invite", "path": "/convites", "query_params": ["event_id"] },
  { "key": "event_detail", "path": "/events/:event_id", "path_params": ["event_id"] },
  { "key": "map", "path": "/map" }
]
```

5) **Define message types**
```json
[
  {
    "key": "invite",
    "label": "Invite",
    "description": "Convites de evento",
    "default_audience_type": "event",
    "default_event_qualifier": "event.invited",
    "allowed_route_keys": ["invite", "event_detail"]
  }
]
```

6) **Push onboarding steps (payload template)**
```json
{
  "layoutType": "fullScreen",
  "closeOnLastStepAction": true,
  "steps": [
    {
      "slug": "notify",
      "type": "cta",
      "title": "Seja avisado",
      "body": "Ative as notificações para continuar.",
      "dismissible": false,
      "gate": {
        "type": "notifications_permission",
        "onFail": {
          "toast": "Ative as notificações para continuar.",
          "fallback_step": "notify"
        }
      },
      "buttons": [
        {
          "label": "Ativar",
          "action": { "type": "custom", "custom_action": "request_notifications" }
        }
      ]
    },
    {
      "slug": "prefs",
      "type": "selector",
      "title": "O que você procura?",
      "body": "Escolha até 3 temas.",
      "config": {
        "selection_ui": "inline",
        "selection_mode": "multi",
        "layout": "tags",
        "min_selected": 1,
        "max_selected": 3,
        "option_source": {
          "type": "method",
          "name": "getTags",
          "params": { "include": ["praias", "restaurantes", "experiencias_no_mar"] },
          "cache_ttl_sec": 3600
        },
        "store_key": "preferences.tags"
      }
    }
  ]
}
```

### Delivery Timing (TTL + Deadline)
Push messages do **not** accept `delivery.expires_at`. Delivery expiration is computed at send time:

- **TTL** is derived by message `type` using `config('belluga_push_handler.delivery_ttl_minutes')`.
- **Optional cap**: set `delivery_deadline_at` (ISO8601) to cap the delivery expiration.
- Effective `expires_at` is `min(delivery_deadline_at, now + ttl)`. If no deadline is provided, it uses `now + ttl`.

Example message payload (account scope):
Note: `option_source` is method-based (`type: "method"` + `name`), resolved by the app via its options resolver/controller. The backend does not accept `endpoint/tags/query` option sources.
```json
{
  "internal_name": "boora_onboarding_dynamic_2026_01_08_manual",
  "title_template": "Bóora! Bem-vindo",
  "body_template": "Vamos personalizar sua experiência.",
  "type": "transactional",
  "active": true,
  "audience": { "type": "all" },
  "delivery": {
    "scheduled_at": null
  },
  "delivery_deadline_at": "2026-02-08T12:00:00Z",
  "payload_template": {
    "layoutType": "fullScreen",
    "closeOnLastStepAction": true,
    "steps": [
      { "slug": "intro", "type": "cta", "title": "Começar" }
    ]
  }
}
```

7) **(Optional) Add telemetry integration**
```json
{
  "type": "mixpanel",
  "token": "mixpanel-token",
  "events": ["push.sent", "push.opened"]
}
```

8) **Enable push**
No body required.

8) **Manual validation checklist**
- Send a push and confirm tap handling opens the expected in-app surface.
- Verify logs show Firebase init, token acquisition, and `/api/v1/push/register` success.
- Confirm the app registers a device with an anonymous token when logged out.
- Confirm the registration payload uses supported platform values (`android` or `ios`).
- Send an invite payload and confirm the invite list updates without a backend refetch.

Notes:
- `/settings/push` manages push-only fields; use `/settings/firebase` and `/settings/telemetry` for those domains.
- `/settings/push` does not accept `push_message_routes` or `push_message_types`; use the dedicated endpoints above.
- `push.message_routes` and `push.message_types` are stored under `push` in the settings document.
- `/settings/push` does not accept `push.enabled`; use `/settings/push/enable` or `/settings/push/disable`.
- `/settings/push` does not accept `push.types` (types come from `message_types`).
- Use `push.max_ttl_days` instead of top-level `max_ttl_days`.
- Telemetry types are unique; `POST /settings/telemetry` upserts by `type`.
- `PATCH /settings/push/route_types` and `PATCH /settings/push/message_types` accept raw arrays of objects (no root key).
- Deletes use `DELETE /settings/push/route_types` or `/message_types` with `{ "keys": ["..."] }`.

### Landlord Routes
Mounted under:
```
{landlord.prefix}
```
Endpoints:
- `GET /{tenant_settings_path}` — show tenant push settings
- `PATCH /{tenant_settings_path}` — update tenant push settings

Auth/middleware:
- `auth:sanctum` + `landlord` + `abilities:push-settings:update`

## Data Model (Summary)
The package provides MongoDB collections for:
- `push_messages`
- `push_message_actions`
- `push_message_metrics`
- `push_devices`
- `settings`

Migrations live in:
```
packages/belluga/belluga_push_handler/database/migrations
```

Tenant migrations must include the package migration path so tenant databases receive
the push collections. Configure the host app to run both migration paths during
tenant provisioning, for example:

```php
// config/multitenancy.php
'tenant_migration_paths' => [
    'database/migrations/tenants',
    'packages/belluga/belluga_push_handler/database/migrations',
],
```

## Request/Response Expectations (Summary)
This package follows the backend schema defined in:
`foundation_documentation/todos/active/TODO-v1-telemetry-and-push-backend.md`

Key behavior:
- `ok=false` on `/data` when inactive/expired/not found
- `active` flag and TTL enforced server-side
- Actions require `step_index` and `idempotency_key`

## End-to-End Flow (Example)
1. **Create message**
   - `POST /api/v1/accounts/{account_slug}/push/messages`
2. **Server persists message + schedules delivery**
3. **Send job executes**
   - calls FCM client
   - updates `accepted` metrics from FCM response
4. **Client receives FCM notification**
   - uses `push_message_id` and fetches `/data`
5. **Client renders payload**
   - sends `/actions` for opened/clicked/delivered/dismissed
6. **Metrics update**
   - action docs recorded
   - aggregate counts incremented

## Extending the Package

### Custom Plan Policy (Multiple Feature Plans)
Use a central plan service and a thin adapter:
```php
namespace App\Services;

use Belluga\PushHandler\Contracts\PushPlanPolicyContract;
use Belluga\PushHandler\Models\Tenants\PushMessage;

class PushPlanPolicy implements PushPlanPolicyContract
{
    public function __construct(private AccountPlanService $plans) {}

    public function canSend(string $accountId, PushMessage $message, int $audienceSize): bool
    {
        $limit = $this->plans->quotaFor($accountId, 'push_messages_per_month');
        $used = $this->plans->usageFor($accountId, 'push_messages_per_month');

        return ($used + $audienceSize) <= $limit;
    }
}
```

### Custom Audience Eligibility
Bind your own eligibility contract implementation (domain-aware rules live in the host app):
```php
use Belluga\PushHandler\Contracts\PushAudienceEligibilityContract;
use App\Services\YourAudienceEligibility;

app()->bind(PushAudienceEligibilityContract::class, YourAudienceEligibility::class);
```

If you want richer quota-check responses, also implement:
```php
use Belluga\PushHandler\Contracts\PushPlanPolicyDecisionContract;

class PushPlanPolicy implements PushPlanPolicyContract, PushPlanPolicyDecisionContract
{
    public function quotaDecision(string $accountId, PushMessage $message, int $audienceSize): array
    {
        return [
            'allowed' => true,
            'limit' => 1000,
            'current_used' => 250,
            'requested' => $audienceSize,
            'remaining_after' => 750 - $audienceSize,
            'period' => 'monthly',
            'reason' => null,
        ];
    }
}
```

### FCM Client Binding
Replace the stub with a real client:
```php
use Belluga\PushHandler\Contracts\FcmClientContract;
use App\Services\YourFcmClient;

app()->bind(FcmClientContract::class, YourFcmClient::class);
```

## Common Integration Questions

**Where do I enforce plan limits?**  
Implement `PushPlanPolicyContract` and bind it. The package will call `canSend(...)` before delivery. To power the quota-check endpoint, implement `PushPlanPolicyDecisionContract` and return a rich decision payload.

**Can one class implement multiple policies?**  
Yes. PHP allows a class to implement multiple interfaces. You can define a single `AccountPlanPolicy` class that implements several feature policies.

**How does route control stay with the host app?**  
The package registers routes but reads paths from `config('belluga_push_handler.routes')`. Override the host config file to change them.

## Notes
- This package is kept in-repo until stabilized, then extracted into its own repository.
- Route paths are owned by the host app; the package registers routes and reads the config.
- Settings remain in the host app; the package extends settings with push-specific sections.
