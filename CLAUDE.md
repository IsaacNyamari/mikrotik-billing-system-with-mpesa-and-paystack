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

Business control and network control are separate:

- **Laravel is the business plane.** Tenants, customers, plans, subscriptions, invoices, payments,
  orchestration.
- **The MikroTik is the network plane.** It authenticates users from its own local user tables.
- **The RouterOS API is how the two meet.** Laravel writes users onto the device and deletes them
  when they expire.
- **WireGuard is the transport plane.** Private management path to routers behind NAT/CGNAT.

### Router-native authentication, not FreeRADIUS

The specification made FreeRADIUS the authentication authority. **That was superseded** on
2026-08-11: users are written directly onto the router instead.

- HotSpot users live at `/ip/hotspot/user`, PPPoE users at `/ppp/secret`.
- A HotSpot buyer's username and password are both their M-PESA transaction code.
- When their time or data runs out, the user row is **deleted from the router**. The payment
  record stays in the database, which is what feeds the ISP's earnings analytics.
- PPPoE is the other way round: the ISP creates the credentials and assigns them to a customer.

FreeRADIUS is deferred, not abandoned. Provisioning goes through an abstraction so a RADIUS
backend can be added later without rewriting the HotSpot and PPPoE layers.

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

- Tenant-owned models use `App\Models\Concerns\BelongsToTenant`, which adds the global scope,
  auto-fills `tenant_id` on create, and refuses cross-tenant reads, writes and deletes.
- The current tenant lives in `App\Support\Tenancy\TenantContext` (a singleton), reached through
  the `Tenancy` facade and resolved from the authenticated user by the `ResolveTenant` middleware.
- **Strict scoping is on** (`config/tenancy.php`). Touching a tenant-owned model with no tenant
  resolved throws `TenantContextMissingException` rather than quietly spanning every ISP. Console
  commands, seeders and platform screens must say what they mean:
  - `Tenancy::forTenant($tenant, fn () => ...)` — act as one ISP.
  - `Tenancy::withoutScope(fn () => ...)` — deliberately span all ISPs.
- Platform super admins have `tenant_id = null` and are the only identity allowed to cross tenants.
  They start unscoped and switch into an ISP through the session.
- `User` is deliberately **not** globally scoped: Fortify looks a user up by email before any
  tenant is known. Use `User::query()->ofTenant($id)` on listing screens.
- RADIUS requests are attributed to a tenant through the registered router/NAS mapping.
- Reports, exports and dashboards enforce isolation too, not just CRUD screens.

Never add `WithoutModelEvents` to a seeder — tenant stamping and UUID generation both ride on
model events, so suppressing them seeds rows that could not exist at runtime.

`ResolveTenant` is pinned ahead of `SubstituteBindings` in the middleware priority list
(`bootstrap/app.php`). That ordering is load-bearing: route model binding runs a scoped query, so
the tenant must already be known or strict scoping rejects every `{customer}` lookup.

`Livewire::test()` invokes a component directly and runs no middleware, so tests using it must
call `Tenancy::set()` themselves. Real requests — full page loads and Livewire's update endpoint —
both go through the web group and resolve the tenant normally.

## Customers and service accounts

A `Customer` is a billing and support identity. A `ServiceAccount` is the network identity, and
one customer may hold several — plans, subscriptions, RADIUS identities and sessions all attach to
the service account, never to the customer.

- Customer numbers (`CUS-000001`) and account numbers (`SVC-000001`) are allocated per ISP by
  `SequenceGenerator`, which locks the tenant row and must run inside a transaction. A number that
  has been used once is never reissued, including after a soft delete.
- Network logins are unique **within** an ISP, not across the platform, so two ISPs may each have a
  subscriber called `john`. Global RADIUS uniqueness is a Phase 7 concern and will be solved with a
  realm-qualified identity rather than by tightening this index.
- The network secret is `encrypted` rather than hashed, because RADIUS provisioning needs the
  cleartext. It is hidden from serialisation and gated behind its own policy check.
- Status changes go through `ChangeServiceAccountStatus`, never by assigning to the model. From
  Phase 7 that action is also where RADIUS is refreshed and live sessions are dropped.
- A blocked customer takes all of their services offline regardless of each service's own status.

## Authorization

- `App\Support\Authorization\Permissions` is the permission catalogue and the single source of
  truth. Every entry becomes a gate ability at boot, so `$user->can('routers.provision')` works.
  Adding a capability means adding it there and reseeding — never inserting a row by hand.
- `App\Support\Authorization\Roles` defines the seven roles from the specification. Platform roles
  carry `tenant_id = null` and are shared by every ISP; a tenant may define extra roles of its own.
- A super admin passes every gate via `Gate::before`. A deactivated user passes none, whatever
  their roles say.

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

## RouterOS integration

Nothing in the application talks to the vendor library or opens a socket itself. Everything goes
through `App\Services\RouterOs`:

- `Contracts\RouterOsConnector` opens a connection to a `Router`; `Contracts\RouterOsConnection` is
  the conversation (`get`, `first`, `add`, `update`, `remove`, `execute`).
- `LiveRouterOsConnection` is the only class that knows `evilfreelancer/routeros-api-php` exists.
- `Testing\FakeRouterOsConnection` is an in-memory MikroTik: rows are stored per menu path, get an
  `.id`, and can be filtered, updated and removed. Tests assert on what the device ends up
  holding, not on a list of calls. `Tests\Concerns\InteractsWithRouters` swaps it in.
- RouterOS refuses commands with a `!trap` rather than an error, so `run()` inspects every response
  and raises `RouterOsCommandException`. `trap()` on the fake reproduces this.
- Timeouts are deliberately short (`config/routeros.php`): provisioning runs while an operator or
  a captive-portal customer waits.

Router API passwords are `encrypted` and `#[Hidden]`. Health fields and `status` are **not**
mass-assignable — they are reported by the device, so the probe assigns them directly. `fill()`
would silently drop them.

Run `php artisan router:test {slug}` to check a device: it reports identity, version, uptime and
which menus the API user can actually reach.

## Build phases

Reordered on 2026-08-11 at the user's direction: routers and the HotSpot/PPPoE layer come before
plans and billing, because the MikroTik integration is what the rest hangs off.

| # | Deliverable | Status |
|---|---|---|
| 1 | Laravel foundation, environment, coding standards | done |
| 2 | Multi-tenancy, users, roles and policies | done |
| 3 | Customers and service accounts | done |
| 4 | Router domain, RouterOS API client, health probe | done |
| 5 | HotSpot and PPPoE plans, pushed to routers as profiles | |
| 6 | PPPoE user provisioning onto `/ppp/secret` | |
| 7 | HotSpot user provisioning and expiry sweeper | |
| 8 | Captive portal | |
| 9 | Lipa na M-PESA, and portal purchase end to end | |
| 10 | Subscriptions, invoices and renewals | |
| 11 | Vouchers | |
| 12 | Monitoring and operational dashboards | |
| 13 | Reports and earnings analytics | |
| 14 | WireGuard management network | |
| 15 | Security review and audit | |
| 16 | Production deployment | |

FreeRADIUS is no longer a phase of its own; it becomes an alternative provisioning backend if and
when scale calls for it.

## Commands

```bash
php artisan migrate          # MySQL, isp_platform
composer lint                # Pint
composer types:check         # Larastan
composer test                # config:clear + lint + types + phpunit
```

Tests run against the real MySQL `isp_platform_testing` database rather than SQLite, because the
production target is MySQL and tenant isolation depends on real foreign-key and index behaviour.
