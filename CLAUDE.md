# Multi-Tenant ISP Management Platform

A multi-tenant ISP billing and network management SaaS. Each tenant is an ISP that manages its own
MikroTik routers, customers, plans, vouchers, invoices and payments.

Source specification: `ISP_Multitenant_Platform_Architecture_and_Workflow.docx`.

## Stack

| Concern | Technology |
|---|---|
| Application | Laravel 13, Livewire 4 + Flux, Fortify |
| Database | MySQL (`isp_platform`; tests use `isp_platform_testing`) |
| Queue / cache | `database` driver locally, Redis on the VPS |
| Network AAA | FreeRADIUS |
| Router control | MikroTik RouterOS API |
| Router transport | WireGuard (`10.8.0.0/24`, management server `10.8.0.1`) |
| Payments | Lipa na M-PESA (Daraja) |
| Production | `isp.solforbs.com` |

Local-first: every workflow is proven on XAMPP before anything is deployed.

## The plane separation

This is the load-bearing decision of the whole design. Business control and network control are
separate, and the boundary is not negotiable:

- **Laravel is the business plane.** Tenants, customers, plans, subscriptions, invoices, payments,
  orchestration. It *provisions* RADIUS records; it never speaks the RADIUS protocol.
- **FreeRADIUS is the network plane.** Authentication, authorization, rate limits, session limits,
  expiry enforcement, accounting. It is never the source of truth for invoices or payments.
- **MikroTik RouterOS API is the device plane.** Configuration, operational commands, monitoring.
- **WireGuard is the transport plane.** Private management path to routers behind NAT/CGNAT.

## Domain chain

```
Customer → ServiceAccount → Plan → Subscription → Invoice → Payment
                │                        │
                └──── RADIUS identity ───┴──→ FreeRADIUS → MikroTik → Internet
```

A customer is **not** a network login. One customer may hold several `ServiceAccount`s (e.g. home
PPPoE on one router plus a backup HotSpot on another), each with its own router, plan and RADIUS
identity.

## Multi-tenancy

Single shared database, `tenant_id` column on every tenant-owned table, enforced by a global scope.

- Tenant-owned models use the `BelongsToTenant` trait, which adds the global scope and auto-fills
  `tenant_id` on create.
- The current tenant lives in `TenantContext` (a singleton), resolved from the authenticated user.
- Bypassing the scope requires an explicit, deliberate call — never an accident.
- Platform super admins have `tenant_id = null` and are the only identity allowed to cross tenants.
- RADIUS requests are attributed to a tenant through the registered router/NAS mapping.
- Reports, exports and dashboards enforce isolation too, not just CRUD screens.

## Hard rules

Carried from the specification. Violating any of these is a defect, not a style choice:

1. Never create tenant-owned data without tenant ownership.
2. Never store router or API secrets in plaintext — encrypt at rest, unique credentials per router.
3. Never make Laravel the RADIUS protocol server.
4. Never make FreeRADIUS the source of invoice or payment truth.
5. Never expose the MikroTik API publicly when WireGuard management is available.
6. Never activate service on M-PESA STK initiation alone — only on a verified callback.
7. Payment callback processing must be idempotent.
8. Provisioning tokens are short-lived and single-use.
9. Every network-changing operation is logged to the audit trail.
10. Every endpoint enforces validation *and* authorization.
11. Orchestration lives in services/actions/jobs, not oversized controllers.
12. Long-running work (router provisioning, notifications, integrations) goes through queues.
13. No secrets in source control.

## Build phases

Built one phase at a time, never all at once.

| # | Deliverable | Status |
|---|---|---|
| 1 | Laravel foundation, environment, coding standards | done |
| 2 | Multi-tenancy, users, roles and policies | in progress |
| 3 | Customers and service accounts | |
| 4 | Plans and subscription lifecycle | |
| 5 | Invoices and billing | |
| 6 | Lipa na M-PESA integration | |
| 7 | FreeRADIUS integration and accounting | |
| 8 | MikroTik router domain and API client | |
| 9 | WireGuard provisioning and router registration | |
| 10 | PPPoE provisioning | |
| 11 | HotSpot and voucher system | |
| 12 | Monitoring and operational dashboards | |
| 13 | Reports | |
| 14 | Security and audit | |
| 15 | Automated tests | |
| 16 | Production deployment | |

## Commands

```bash
php artisan migrate          # MySQL, isp_platform
composer lint                # Pint
composer types:check         # Larastan
composer test                # config:clear + lint + types + phpunit
```

Tests run against the real MySQL `isp_platform_testing` database rather than SQLite, because the
production target is MySQL and tenant isolation depends on real foreign-key and index behaviour.
