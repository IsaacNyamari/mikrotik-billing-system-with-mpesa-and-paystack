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

## Plans and RouterOS profiles

A `Plan` is a business object. Its network settings are copied onto each router as a RouterOS
profile by `PlanProfileSynchroniser`; `PlanRouterProfile` records that copy per device, because
the two drift whenever a push fails or an operator edits the router by hand.

The split matters: **the profile carries what everyone on the plan shares** (speed, devices per
login); **the individual user row carries the personal quota** (time, data), because two people on
the same plan each buy their own hour.

- `plans.code` becomes the RouterOS profile name, so it is validated against
  `/^[A-Za-z0-9][A-Za-z0-9._-]*$/` and is unique per ISP.
- A profile that already exists under that name is **adopted and updated**, never duplicated — an
  ISP that hand-built profiles before adopting the platform keeps one of each.
- `rate-limit` is written `upload/download` — RouterOS reads it rx/tx from the router's point of
  view, which is the reverse of how the package is sold. Getting this backwards sells a 10 Mbps
  plan that uploads at 10 and downloads at 1.
- Times are sent to RouterOS in seconds (`3600s`). The device normalises them to `1h`/`1d` itself,
  which is verified against real hardware.
- Sync never throws. An unreachable router is recorded on the pairing for an operator to retry.

`php artisan plan:push {code} --router=slug` pushes from the terminal.

## Provisioning users onto the router

Two provisioners, deliberately asymmetric because the products are:

**`HotspotUserProvisioner`** — a walk-in buys a package. `issue()` records the purchase *first*,
then pushes; a router that is briefly unreachable must not lose a customer who has paid. Username
and password are both the payment reference. `limit-uptime` and `limit-bytes-total` go on the
**user**, not the profile — two people on the same plan each bought their own hour. Re-issuing the
same reference updates rather than duplicating, because M-PESA delivers callbacks more than once.

`remove()` reads usage off the device **before** deleting the row: once it is gone RouterOS has no
memory of it, and that reading is the only evidence of what was consumed. The `hotspot_users`
record always survives — it is what earnings reporting is built from.

**`PppoeSecretProvisioner`** — the ISP creates the line and assigns it. Suspension *disables* the
secret rather than deleting it, since a suspended line usually comes back; only termination
removes it. `ChangeServiceAccountStatus` calls this, so a suspension actually cuts the line
instead of only changing a database row.

Both **disconnect the live session** after taking access away. Deleting a HotSpot user or
disabling a PPP secret does not end a session already running, so without this a suspended
customer stays online until their session happens to drop.

Neither provisioner throws on a router failure — the error is recorded on the row for the sweeper
or an operator to retry.

## Payments and the captive portal

A customer joins the wifi, the HotSpot redirects them to `/p/{portal_key}`, they pick a package,
pay from their phone, and are logged in without ever typing a username.

- The **portal key** on `routers` identifies the device — and so the ISP — in a public URL that
  gives away neither the tenant nor a sequential id. The portal is unauthenticated, so it resolves
  the tenant itself with `Tenancy::withoutScope()` and then binds it. It rebinds on `hydrate()`,
  because no middleware can do it for a request with nobody signed in.
- **Access is granted on a verified result, never on the push being accepted.** The push only
  means the provider agreed to ring the phone.
- Safaricom does not sign callbacks. The unguessable `callback_token` in the URL is what
  identifies the ISP, and the endpoint is CSRF-exempt (`bootstrap/app.php`).
- The callback **always answers 200**, including for an unknown token or a malformed body: a
  non-200 makes Safaricom retry, which only helps when the problem is transient.
- `CallbackProcessor` is safe to run repeatedly — an already-settled payment returns untouched.
  Safaricom delivers callbacks more than once.
- The **M-PESA receipt becomes the username and password** of the HotSpot login.
- The amount Safaricom reports as paid overwrites the amount asked for; the ISP's books should
  show the money that actually moved.
- If the router is unreachable when the callback lands, the payment still settles as paid and the
  access is left `failed` for `hotspot:sweep --retry` to pick up. The money has moved either way.
- Daraja rejects decimal amounts, so prices are rounded **up** — rounding down would undercharge.
- Phone numbers are normalised by `App\Support\PhoneNumber`, not by the gateway, so the stored
  number is comparable no matter how the customer typed it.

### Asking, not only being told

A webhook only arrives if the provider can reach this installation — which it cannot from a
laptop, from behind NAT, or when the tunnel is down. Waiting for one leaves a customer holding
the M-PESA confirmation SMS while the page spins, so **the portal asks the provider outright on
every poll** through `PaymentReconciler`.

- This is not a weakening of the verified-result rule: it *is* a verification, from the same
  provider, carrying the same facts its callback would.
- `PaymentGateway::verify()` returns a `VerificationResult` — the status the payment should
  take, or `null` when the provider did not say. An outage or an unrecognised answer settles
  **nothing**; only a provider's own conclusion does.
- Each gateway reads its own provider's shape, and the same reading serves both the webhook and
  the poll — otherwise whichever arrived first would decide what the customer is told.
- `PaymentStatus::Cancelled` is kept apart from `Failed`. The customer pressed cancel a second
  ago; a page calling it a failure reads as the page being broken. Daraja says so with
  `ResultCode` 1032, Paystack only in the wording of `gateway_response`.
- Daraja carries a **20-second grace** before it is asked (`verificationGraceSeconds()`): its
  query reports whether the money moved but not the M-PESA code, and that code is what the login
  is named after. Paystack's answer is complete, so it is asked at once.
- Reconciling takes a per-payment cache lock, and `settle()`/`close()` re-read the row before
  writing. A webhook and a poll landing together must not issue two logins for one purchase.
- `payments:reconcile` runs every minute for the customers who closed the tab. Their money moved
  too, so their purchase still has to be recorded and their login still has to exist.

### Two providers, one contract

An ISP without a Daraja app can collect through **Paystack** instead. Both sit behind
`App\Services\Payments\Contracts\PaymentGateway`, and `PaymentGatewayManager` picks the one
the tenant chose (`tenants.payment_provider`), falling back to whichever is configured when they
were never asked.

- Paystack uses the **mobile-money charge**, not a hosted checkout. The customer is on a captive
  portal, so a hosted page would need a walled-garden hole for every asset it loads. A charge
  prompts the phone exactly as Daraja does, and the portal behaves identically either way.
- Paystack **signs its webhooks**; Daraja does not. The signature is verified against the raw
  body — re-encoding decoded JSON changes whitespace and key order, and it would never match.
  Which ISP's key to check against comes from the payment the reference belongs to.
- Paystack amounts are in the currency's smallest unit, so KES goes over as cents.
- Paystack requires an email per charge and a walk-in has only given a phone, so one is derived
  from the number against a configured domain.
- The M-PESA code is in **`data.receipt_number`** — confirmed against a real settled charge.
  `authorization.mobile_money_number` is the *payer's own phone number*: using it as the receipt
  names the customer's login after their phone number, which is both wrong and theirs to keep
  private.

### What the customer is told, and what the ISP is told

The provider's own wording reaches the portal only when it describes what happened on the
customer's phone — "insufficient funds" tells them exactly what to do next. A failure from before
the prompt was ever sent is not about them, and `Payment::reachedTheCustomer()` (a provider handle
exists at all) is what tells the two apart.

Paystack refusing a charge with `type: api_error` / `code: unprocessed_transaction`, an outage, or
an ISP's half-finished setup all land in that second group: the customer gets a plain instruction,
the provider's exact words stay on the payment row, and `PaymentCollector` logs them. Showing the
setup ones would also tell a stranger on the wifi about the ISP's own configuration.

`DarajaPaymentGateway` is a thin adapter over the existing M-PESA code rather than a rewrite:
that path is the one proven against a real Safaricom account.

### Credentials are per ISP, in the database

Every tenant collects into its own till, so `mpesa_configurations` is tenant-owned: one row per
ISP (`unique(tenant_id)`), `BelongsToTenant`, credentials `encrypted` and `#[Hidden]`, and a
`callback_token` that is unique platform-wide. An ISP enters its own through **Settings → M-PESA**
and never sees another's.

`.env` and `php artisan mpesa:configure` hold room for exactly **one** set, so they are a
development and first-run convenience only — never the source of truth. Nothing outside that
command reads M-PESA credentials from config.

### Portal appearance is per ISP

`portal_settings` (one row per tenant) holds branding, copy, colours and custom CSS, edited at
**Settings → Portal**. Every column is nullable and `PortalSetting::current()` returns an unsaved
instance when there is no row, so a portal renders for an ISP that has customised nothing.

The portal layout carries **no `@vite` and no Flux**. It is the one page that must render for
someone who has paid nothing and can reach almost nothing, and a compiled stylesheet ties it to
the asset build — a stale `public/hot` points every asset URL at a Vite dev server on localhost,
which a phone can never reach. The CSS is inlined; the only external request left is Livewire's.

Two values reach a stylesheet, so both are guarded rather than escaped:

- `primary_color` is validated as `#rrggbb` on the way in **and** again in `primary()`, with the
  column narrow enough that a longer injection cannot be stored at all.
- `custom_css` has angle brackets stripped, so it cannot close the element and open a script.

Button and badge text colours are chosen from each colour's luminance, so an ISP picking pale
yellow does not end up with white text nobody can read. `accent_color` is optional and falls back
to a darker step of the brand colour — a page whose badges were a colour nobody chose would look
like an accident.

### The buying flow is two taps

Packages are **tiles**, two to a row on a phone, each with a badge carrying its validity, the
price, and one instruction. The wording of that instruction (`buy_button_text`), what the money is
called (`currency_label`), the banner, the tile style and one-per-row versus tiles are all per
ISP — a HotSpot's front page is the whole of what most customers ever see of the ISP.

- **Tapping a package chooses it; the number is asked for on the next screen.** Someone holding a
  phone in one hand is asked one thing at a time, and their number is worth asking for only once
  they have decided what they are buying.
- `choose()` reads from the same list the page offered, so an id typed into the wire cannot buy a
  package this ISP withheld or never sold.
- **Once paid, the credentials are posted to the router immediately, with nothing to press.**
  Asking someone who has just paid to confirm that they want what they bought is how people end
  up stranded on a page. A manual button exists but stays hidden unless the automatic post has
  not navigated away after four seconds.
- Paid but no login yet means the router was unreachable when the money landed. The portal keeps
  polling and retries issuing it rather than making anyone pay twice.

### Scheduled work (`routes/console.php`)

- `hotspot:sweep --retry` every minute — removes spent packages, and re-pushes ones that were paid
  for while a router was down. A package that expired while stuck is *not* resurrected.
- `payments:reconcile` every minute — asks each provider what became of payments still waiting,
  for the customers who paid and then closed the page.
- `router:test` every five minutes — keeps device health honest and records outages.

The scheduler must actually be running: `php artisan schedule:work`.

### Production (`isp.solforbs.com`)

Ubuntu 24.04 at `178.162.241.172`, **shared** with `sms`, `panel`, `mail` and the apex site.
Nothing in a deploy may disturb those: the app gets its own nginx server block, its own MySQL
database and user, and its own Redis prefix and database numbers, and it changes nothing else.

- `/var/www/isp`, served by nginx over the shared `php8.3-fpm.sock` pool. Code is `root:www-data`
  and read-only to the web user; only `storage` and `bootstrap/cache` are writable by it, and
  `.env` is `640 root:www-data`.
- `deploy/push.sh` sends the working tree from the development machine and runs
  `deploy/release.sh` on the server. There is no git remote, so what is on that machine is what
  runs. `.env` is never transferred.
- `isp-scheduler.service` and `isp-queue.service` are systemd units, both `Restart=always`. The
  scheduler is not optional: expiry, `payments:reconcile` and router health all ride on it.
- **`APP_TIMEZONE=Africa/Nairobi`** in production, UTC locally and in tests. A payment stamped
  three hours off is one an operator cannot reconcile against an M-PESA statement.
- `php artisan platform:admin` creates the super admin, interactively. The platform deliberately
  seeds no account: a seeded admin is one with a password somebody wrote down.
- Certificates come from certbot `certonly --webroot` against `/var/www/html`, so renewal never
  rewrites the server block.

### The live test device

An RB951Ui-2HnD on RouterOS 7.23.2, reachable on the LAN. Its HotSpot is already configured —
server `billing-hotspot` on `bridge-hotspot`, pool `10.10.10.10-254`, DHCP, server profile at
`10.10.10.1` with `login-by=http-chap,http-pap`. **PPPoE has no server**, so PPP secrets will not
authenticate anything until one is bound to an interface.

## Reaching routers that nobody can reach

Routers live on other people's connections: behind NAT, behind CGNAT, on dynamic addresses,
sometimes on a 4G dongle. None can be dialled from the platform, and asking an ISP to forward
a port on every site is both a security problem and a support burden. **The router dials out
instead**, over WireGuard, and `wg_address` becomes the address the RouterOS API is spoken to
on.

That creates a chicken and egg: everything else in this system configures a router over the
API, and a router behind NAT has no API to configure it over. `RouterSetupScript` is the way
out — generated here, carried by the operator, pasted into the device's own terminal. It is
the only channel that always works, so what it contains has to be right the first time.

- **The keypair is generated by the platform**, not the device. Letting RouterOS make its own
  and reading the public half back is exactly what cannot be done before the tunnel exists.
  The private key is `encrypted` and `#[Hidden]`, like the API password beside it.
- Curve25519 keys must be **clamped**, or they look fine and never complete a handshake. The
  scalar multiplication goes through `paragonie/sodium_compat`, because XAMPP ships without
  ext-sodium and this platform is proven locally before it is deployed.
- **`persistent-keepalive` is load-bearing.** A NAT mapping with no traffic through it commonly
  disappears in about 30 seconds, and without the keepalive the platform would reach a router
  only while it happened to be talking.
- The script's **order is a security property**: the tunnel first, then the API bound to it and
  firewalled off every other interface *before* it is enabled. Opening the API first — even
  briefly — advertises an admin service to the internet.
- Addresses are allocated **across every ISP**, since the tunnel is one network, and are never
  reissued even after a router is soft-deleted: the old address is still written into a device
  somewhere. Regenerating a script keeps the same tunnel, or every deployed router would need
  re-pasting.
- Names interpolated into the script are stripped to `[A-Za-z0-9._-]`. They sit inside RouterOS
  quotes, and a name carrying one would produce a script that does something other than it
  appears to.
- `wireguard:peers` renders the other half. WireGuard is mutual: a router that knows about us
  gets nowhere unless the server knows about it too. It prints rather than applies, because
  rewriting a live tunnel is not a side effect a report should have.

On the production box: `wg0` at `10.8.0.1/24`, `wg-quick@wg0` enabled, UDP 51820 open in ufw,
and `WIREGUARD_PUBLIC_KEY` in the app's environment. **Management only** — no forwarding and no
NAT, so the tunnel never becomes an ISP's accidental transit provider.

## The platform's own business

The ISPs are the customers. What they pay for the platform is modelled separately from what
their subscribers pay them — `tenant_subscriptions`, `tenant_invoices` and `tenant_payments`,
none of them tenant-scoped models. Two reasons, both load-bearing: an ISP locked out for
non-payment must still reach its own bill, and every earnings figure shown to an ISP is built
from `payments`, so the platform's fees have no business appearing in their revenue.

- **KSh 2,000 once**, then **3–5% of HotSpot collections** and **KSh 15 per active PPPoE line a
  month**. The rate is per ISP, held between the published bounds, and **copied onto the
  subscription at sign-up** — changing the price list never re-prices an ISP who already agreed
  to something else.
- Commission is charged on **settled payments only**. A cancelled prompt is not money.
- PPPoE is counted at billing time on lines that are **active**; suspended and terminated ones
  are not carrying anybody.
- A month with no sales and no lines raises **no invoice**. A bill for zero can fall overdue and
  cost an ISP the console for owing nothing.
- Every invoice stores its `breakdown`. An ISP querying a bill is owed the arithmetic.

### Registering makes you an ISP

There is no application to approve. `CreateNewUser` opens a tenant, makes the registrant its
`ISP_OWNER` and raises the subscription, all in one transaction — half of that is worse than
none, since a user with no ISP cannot work and an ISP with no owner cannot be reached. What
gates them is money, not permission, and that is a decision they can act on at any hour without
us. Registration therefore depends on the **seeded role catalogue**; without it `assignRole`
fails and the POST 404s.

### The gate

`EnsureSubscriptionIsActive` (alias `subscribed`) holds an ISP out of the console until the
setup fee is paid, and again once an invoice passes its due date **plus** the grace period.
Three things it deliberately never touches:

- **The billing screen.** An ISP shut out of the page where they pay can only get back in by
  telephoning somebody, which turns every late payment into support work.
- **Super admins.** They are the people who put a lapsed account back in order.
- **The captive portal and the callbacks.** They sit outside `auth` entirely — a customer who
  bought an hour of wifi is not a party to the argument between their ISP and the platform, and
  an ISP that keeps collecting is an ISP that can pay.

`billing:run` raises invoices nightly rather than monthly: a scheduler that missed the first of
the month would skip a month's income, and raising one that already exists does nothing.

The platform's own M-PESA credentials live in `config/platform.php` from the environment, not
in `mpesa_configurations` — there is one set for the whole installation, and it collects *from*
ISPs rather than for them. It is handed to the proven Daraja gateway as an unsaved
`MpesaConfiguration`, and it has its own callback route and token because the tenant callback
looks payments up in a table this money never touches.

## The public site

`resources/views/pages/frontend/` on the `components.layouts.frontend` layout, built from
`components/frontend/*` so a band of the site is described rather than hand-assembled. Unlike
the captive portal it uses `@vite` — everyone reading it already has internet.

Prices on the site are read from `config/platform.php`, never typed into the page, so what a
prospect is quoted and what the system bills cannot drift apart.

## Build phases

Reordered on 2026-08-11 at the user's direction: routers and the HotSpot/PPPoE layer come before
plans and billing, because the MikroTik integration is what the rest hangs off.

| # | Deliverable | Status |
|---|---|---|
| 1 | Laravel foundation, environment, coding standards | done |
| 2 | Multi-tenancy, users, roles and policies | done |
| 3 | Customers and service accounts | done |
| 4 | Router domain, RouterOS API client, health probe | done |
| 5 | HotSpot and PPPoE plans, pushed to routers as profiles | done |
| 6 | PPPoE user provisioning onto `/ppp/secret` | done |
| 7 | HotSpot user provisioning and expiry sweeper | done |
| 8 | Captive portal | done |
| 9 | Lipa na M-PESA, and portal purchase end to end | done |
| 10 | Subscriptions, invoices and renewals | |
| 11 | Vouchers | |
| 12 | Monitoring and operational dashboards | |
| 13 | Reports and earnings analytics | done |
| 14 | WireGuard management network | done |
| 15 | Security review and audit | |
| 16 | Production deployment | done |

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
