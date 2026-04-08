# Belluga Email (`belluga/email`)

Tenant-scoped transactional email delivery package for Belluga.

## Purpose

This package centralizes:
- tenant-public transactional email send flow
- Resend HTTP transport wiring for the first MVP provider

## Domain Concepts And Invariants

- Public clients submit provider-agnostic tester-waitlist lead data only.
- The package never exposes provider secrets to public clients.
- Delivery requires a host-provided tenant configuration source and tenant display-name resolver.
- The first provider is Resend, but the public endpoint contract stays provider-agnostic.

## Scope

Owned by the package:
- tenant-public send request/controller/service
- Resend payload composition for the tester-waitlist transactional email

Not owned by the package:
- settings-kernel namespace registration
- host tenant-context resolution
- host settings-kernel adapters
- host route mounting
- host auth/tenant middleware groups
- generic template management
- secret storage hardening beyond the current settings-kernel persistence path

## Data Model And Migration Scope

- Migration scope: `tenant`
- This package currently does not ship database migrations or persistent models.
- Tenant-specific delivery settings are owned by the host via the Belluga settings kernel.

## Host Wiring

- Provider: `Belluga\Email\EmailServiceProvider`
- Host integration provider: `App\Providers\PackageIntegration\EmailIntegrationServiceProvider`
- Host route file:
  - `routes/api/packages/project_tenant_public_api_v1/email.php`
- Host must bind:
  - `Belluga\Email\Contracts\EmailSettingsSourceContract`
  - `Belluga\Email\Contracts\EmailTenantContextContract`

## Public API

### `POST /api/v1/email/send`

Tenant-public transactional email send entrypoint.

Payload:
```json
{
  "app_name": "Guarappari",
  "submitted_fields": [
    {
      "label": "Seu Nome",
      "value": "Maria"
    },
    {
      "label": "E-mail",
      "value": "maria@example.com"
    },
    {
      "label": "WhatsApp",
      "value": "27996419823"
    },
    {
      "label": "Qual o seu sistema operacional?",
      "value": "Android"
    }
  ]
}
```

Success:
```json
{
  "ok": true,
  "provider": "resend",
  "message_id": "49a3999c-0ce1-4ea6-ab68-afcd6dc2e794"
}
```

Integration pending:
```json
{
  "ok": false,
  "message": "Integracao de email pendente. Informe ao administrador do site."
}
```

## Authentication And Authorization Boundary

- Package-owned:
  - request validation for public lead payload envelope
  - provider delivery attempt and provider error translation
- Host-owned:
  - tenant/public route mounting and middleware stack
  - tenant resolution
  - settings namespace registration and write authorization
  - secret persistence strategy
- The package intentionally does not enforce host auth policies or tenant-access middleware.

## Host Integration Guide

1. Register `Belluga\Email\EmailServiceProvider`.
2. Register a host integration provider that binds the required contracts and registers any host-owned settings namespace.
3. Mount the package controller from a host-owned route file under the desired tenant-public API group.
4. Persist the tenant-owned Resend envelope fields in the host settings kernel.

## Validation Rules

- public endpoint validates a provider-agnostic ordered `submitted_fields` envelope
- host-managed tenant `resend_email` settings validation mirrors Resend envelope expectations where feasible:
  - `from` allows sender syntax with optional friendly name
  - `to` max 50 recipients
  - `cc`, `bcc`, `reply_to` are optional email arrays

## Notes

- Resend still requires a verified sending domain externally; this package only validates payload shape and forwards to the provider.
- The public frontend remains provider-agnostic and never receives the Resend token or sender defaults.

## Validation Commands

- `python3 delphi-ai/skills/wf-laravel-create-package-method/scripts/assert_package_decoupling.py --package-dir /abs/path/to/laravel-app/packages/belluga/belluga_email --app-dir /abs/path/to/laravel-app/app --app-provider /abs/path/to/laravel-app/app/Providers/AppServiceProvider.php --check-host-bindings`
- `composer run architecture:guardrails`
- `php artisan test --filter=TenantEmailSendControllerTest`
- `php artisan test --filter=SettingsKernelControllerTest`

## Known Limitations And Non-Goals

- Only the tester-waitlist transactional flow is implemented.
- The package does not own admin UI or settings persistence UX.
- The package does not yet support templates, attachments, scheduled send, or webhook callbacks.
