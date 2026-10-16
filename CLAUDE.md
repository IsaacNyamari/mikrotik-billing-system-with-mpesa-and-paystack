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
- **A super admin outside an ISP sees only the platform** (`platform.overview`: system stats
  and what ISPs paid the platform by month; ISPs; ISP subscriptions; Platform payments; their
  own account). `ResolveTenant` redirects every ISP screen there. An ISP is reached only by
  **Open as this ISP** on the ISPs page (`tenants.select`, now at `platform/isps`), which is
  impersonation: the ISP's own sidebar and pages, an amber banner naming the ISP, and **Exit
  to platform** (`platform.exit`). Both ends are audited on the ISP's own trail
  (`platform.impersonation_started` / `_ended`).
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

### HotSpot buyers are shown beside accounts, not turned into them

A walk-in never becomes a `Customer` — they gave a phone number, and one `Customer` row per
hourly purchase would bury the handful of accounts the list exists to manage. But an ISP selling
only HotSpot packages then has **no `Customer` rows at all**, and opens Customers to a blank
table while people sit on their wifi having paid (production, 2026-08-13: 0 customers, 4 settled
payments). True, and useless.

So the screen carries two lists behind a tab. **Accounts** is unchanged. **HotSpot buyers**
groups `hotspot_users` by phone — packages bought, spent, last bought, whether anything of theirs
is live — with a modal listing that number's purchases and their codes, which is the conversation
that starts "I paid earlier". `activeTab()` opens on buyers when there are no accounts and there
are buyers: the answer being one tab away is the whole of the original complaint.

Two details that are not cosmetic:

- **"Live" is computed from `expires_at`, not from `status`.** The sweeper runs once a minute, so
  a spent package reads `active` for up to a minute after it stopped working. The clock is the
  authority.
- Spend comes from `hotspot_users.amount`, which `grantAccess()` overwrites with the sum the
  provider reported as actually paid — so this agrees with Earnings rather than with the price
  list.

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

### Latency, not bandwidth

What a customer experiences as a slow portal is never throughput — these commands are tens of
bytes. It is the delay before the first one gets through, and four things remove it:

- **`PooledRouterOsConnector`** is the bound `RouterOsConnector` (`scoped`, so it is discarded
  between jobs). One connection per router for the life of a request: issuing a HotSpot login
  used to pay for two TCP handshakes and two RouterOS logins, because the profile synchroniser
  and the user provisioner each opened their own. `PooledRouterOsConnection::disconnect()` is a
  no-op — callers still close, and are still right to — while a transport failure marks the
  connection broken so the pool reopens rather than handing on a dead socket. A
  `RouterOsCommandException` does **not** break it: that is the device disagreeing, not the
  network failing. Tests bind `FakeRouterOsConnector` with `instance()` and bypass the pool.
- **`PlanProfileSynchroniser::ensure()`** on the customer-waiting path instead of `sync()`. The
  profile is re-pushed only when the plan has changed since the last push; somebody buying the
  same bundle as the last twenty customers waits for their own login and nothing else.
- **`keepalive_seconds` is 15**, inside both the usual ~30s NAT timeout and WireGuard's 120s
  rekey, so the tunnel is already up when somebody pays rather than being rebuilt while they
  wait. ~6 MB per router per month.
- **`wireguard.mtu` is 1380**, under RouterOS's 1420 default. A PPPoE line or a 4G dongle makes
  the default too big, and a path that swallows the ICMP reply turns that into a command that
  *hangs* rather than one that fails — much the worst outcome. The script also exempts tunnel
  traffic from mangle, so a queue tree an ISP adds later cannot put provisioning behind a
  customer's download, and pings the server at the end rather than waiting for the first
  keepalive.

`connect_timeout` is 3s with `attempts` 2: short, because a router that is down is down now, but
with a retry because a tunnel mid-handshake is not the same as an outage.

**Routers already deployed keep the settings pasted into them.** Regenerating and re-pasting
their setup script is what applies the new keepalive and MTU.

Router API passwords are `encrypted` and `#[Hidden]`. Health fields and `status` are **not**
mass-assignable — they are reported by the device, so the probe assigns them directly. `fill()`
would silently drop them.

Run `php artisan router:test {slug}` to check a device: it reports identity, version, uptime and
which menus the API user can actually reach.

## The router console (Winbox from the dashboard)

An ISP must be able to do everything from the dashboard that they would otherwise open Winbox
for. `routers/{router}/console/{menu}` is one Livewire page over every RouterOS menu, because
Winbox is itself a generic menu browser: a table, a form per row, enable/disable/remove.

- A screen is a `ConsoleMenu` under `app/Services/RouterOs/Console/Menus` plus a line in
  `ConsoleMenus`. It declares the path, the columns, the writable `fields()` with their
  validation, and what it allows (add, edit, remove, toggle, row commands).
- Every write goes through `RouterConsole`, which **sends only the properties the menu
  offers**, re-reads the row from the device before acting, and audits as
  `router.console.{action}` with old and new values.
- **`protects()` keeps the platform's foothold out of reach**: the tunnel interface, the
  uplink, anything the setup script commented (`platform-*`, "Managed by the platform"…), and
  per menu what RouterOS manages itself (the HotSpot's dynamic queues). Disabling the tunnel
  from a web page would succeed and then cut the page off for good. A row also cannot be
  *given* a protected comment, or an ISP could lock their own config away.
- Reading needs `view`; changing needs `command` (`routers.command`), the existing line
  between looking at a device and reconfiguring it.
- Commands run inline, not queued: each is tens of bytes and the operator is watching for it.
- Menus: HotSpot (online, users, profiles, devices, IP bindings, walled garden), PPP
  (connected, users, profiles), Interfaces, IP (addresses, pools, DHCP leases, routes, DNS),
  Firewall (NAT, rules, address lists), Simple queues, Identity, Log — plus a Reboot button.
  A policy the platform's API group lacks (`reboot` on routers set up before it was added)
  is refused by the device until the setup script is pasted again.
- **What the platform keeps in step is shown, not edited** (`PlatformRecords`): profiles
  pushed from a plan, HotSpot packages sold through the portal, PPPoE secrets that belong to
  a service account. Editing the device copy would be reverted by the next sync, or worse,
  leave the device and the books disagreeing. The message says where to change it instead.
- **Firewall filter rules cannot be added**, only labelled, toggled and removed. Order is
  meaning there: appended after the final drop a rule never matches, placed above the API
  guard it can open the router. Blocking somebody goes through an address list.
- Dynamic rows (`dynamic=true`) are RouterOS's own and are read-only, except on menus that
  are dynamic by nature (leases, sessions, hosts). An empty field on an edit is left alone
  rather than written as `""`, which RouterOS refuses for most properties; only `comment`
  and fields marked `clearable` are cleared.

### HotSpot setup

`routers/{router}/hotspot` is Winbox's HotSpot Setup wizard applied over the API, for a
router the platform can already reach (one it cannot is set up by pasting its script).
The sidebar's **Router console** and **HotSpot setup** go through `routers.manage`, which
lands on the first router; the console switches between several.

`HotspotInstaller` does what the wizard does — address on the interface, pool, DHCP server
and network, DNS (`allow-remote-requests`), server profile, the HotSpot, masquerade — then
what the wizard does not and this platform needs: the walled garden for the portal and the
payment providers, fasttrack removed, and the login pages fetched onto the device.

- Runs as the queued `SetUpHotspot` job; the page polls a cache entry for the step report.
- **Re-runnable**: each step replaces what the last run made (by name or `platform-`
  comment) instead of adding a second, so it doubles as "fix my HotSpot".
- Core steps stop at the first failure — a DHCP server pointing at a pool that was never
  made looks configured and is not. Walled garden and pages report separately.
- Refuses the uplink, the tunnel, a port that is in a bridge (offer the bridge), an
  interface that already has another DHCP server or HotSpot, and a public address range.
- A gateway in the middle of the network gives a pool of two ranges around it.
- **A Wi-Fi name** switches the radio on (open, `platform-open` / empty auth) and bridges it
  with the chosen port in `bridge-hotspot`, where the HotSpot then runs. Either radio package
  is driven; `bridge-tech` is never offered.
- **The HotSpot's network is removed from every interface first** (production, 2026-10-15:
  `10.10.10.1` left on `bridge-tech` while DHCP and the HotSpot ran on wlan1 made both
  `invalid`, and phones sat on "obtaining IP address"). An address in that network belonging
  to the technician port, tunnel or uplink is refused instead.
- Saves what it built into `setup_options`, so the setup script and the wizard agree.

### PPPoE setup and subscribers

The sidebar's **PPPoE** group mirrors HotSpot: **Subscribers** (`pppoe`, every PPPoE line by
payment standing: due in 3 days, cut for non-payment, never paid, suspended by hand), **PPPoE
setup** (`routers/{router}/pppoe`), **Packages** (`plans?type=pppoe`) and **Online now** (the
console's `ppp-active`). Lines are still created on the customer's page.

`PppoeServerInstaller` (queued as `SetUpPppoe`, report polled from cache like HotSpot setup) is
the setup script's PPPoE section over the API: an address pool, the `platform-pppoe` default
profile, the PPPoE server on the chosen interface, and masquerade (`platform-pppoe`). It refuses
the uplink, the tunnel, `bridge-tech`, a bridged port (offer the bridge), another service name
already on that interface, and a network overlapping the HotSpot's or the management network.
Re-runnable, and saves `with_pppoe`/`pppoe_*` into `setup_options`.

**Package profiles carry the addresses** (`PlanProfileSynchroniser::pppProfile()`): a secret
takes `local-address`/`remote-address` from its own profile, never from the server's default,
so a package profile without them lets a subscriber authenticate and then get no IP. Once the
router has PPPoE (`with_pppoe`), they are added from its `pppoe_gateway` and pool, and the
installer re-pushes every PPPoE package at the end.

## Outages, compensation and logging back in

`routers:watch` runs every minute and queues one `WatchRouter` job per router
(`--sync` runs them inline, for a laptop with no worker). `RouterWatcher` does one of three
things per router:

- **No answer** (`RouterStatus::Offline` only — `Error` means it answered): open a
  `RouterOutage`, `started_at` = the router's previous `last_seen_at`. Measured generously on
  purpose: a minute too long costs the ISP a minute, too short costs them a customer.
- **Answers with an outage open**: close it and read the cause off the device. Uptime says
  whether it restarted during the outage (else `Connectivity`); if it did, its log says
  "rebooted without proper shutdown" after a power cut (`Power`) and not after a clean
  restart (`Reboot`). The log is in memory and starts afresh at boot, so that line is there
  exactly when it is needed. Then `OutageCompensator` runs.
- **Answers normally**: note each HotSpot session's MAC and address on its `hotspot_users`
  row (`last_mac_address`, `last_online_at`). That is what makes re-login possible later.

`OutageCompensator` gives each customer the part of the outage that fell inside *their*
package (from purchase or outage start, to expiry or outage end), skipping anything under a
minute, adds it to `expires_at`, and re-pushes the user so the device comment agrees. One
`outage_compensations` row per customer per outage (unique index, `createOrFirst`) makes it
exactly-once however often the recovery is seen. `Failed` packages count: that is somebody
who paid while the router was unreachable.

`HotspotRelogin` logs customers who were online back in with `/ip/hotspot/active/login`
(user, password, the noted MAC, and the address the MAC has *now* — DHCP may have changed
it). Retried every minute for 30 minutes because phones take a while to rejoin; gives up
after that or five refusals. A customer who signed in themselves counts as done.

**`hotspot:sweep` skips routers with an open outage.** Otherwise the first minute a router
answers again, the sweeper would expire the very customers the watcher is about to extend.

**The ISP is told at once** (`OutageAlerts`, Settings → SMS → Router alerts), on the first
failed check rather than after a grace period:

- **Down** when an outage opens, **back** when it closes with the duration and the cause
  (only known then). The outage's `alerted_at` is claimed with a conditional update before
  sending, so overlapping watchers alert once; the recovery only follows a down alert.
- **Failing** (`RouterStatus::Error`: it answers but refuses to be managed) once per episode
  via `routers.error_alerted_at`, and **working again** when it clears.
- **Getting it there**: `SmsSender::sendAlert()` tries the ISP's HostPinnacle account, then
  the platform's (`HOSTPINNACLE_*`), each attempt an `sms_messages` row keyed by
  `router_id` + `alert_key`. `retry()` runs on every watch and re-sends anything not yet
  accepted, a minute apart, up to `MAX_ATTEMPTS` rows within six hours. A copy is emailed to
  the ISP's `contact_email`. "Sent" means HostPinnacle accepted it; handset delivery reports
  are not read.
- Up to five numbers, else the ISP's `contact_phone`. Any hour, and even with subscriber
  texts switched off (`operator`).

Customers are told on the captive portal (`outageNotice()`): exactly how much time their own
package gained, or, for everyone else, that it happened and running packages were credited.
Shown for three hours after recovery. The ISP sees outages on the router page.

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

**One reference is one package, in the database and not only on the device.** The re-issue
guard used to live solely in `provision()`, which asks the router whether it already has the
user. Nothing asked whether *this* table already had the purchase. A Paystack webhook and the
portal's own 3-second poll both reached `grantAccess()` inside the same second, both read
`hotspot_user_id` as null because neither had written yet, and the loser hit
`hotspot_users_router_id_username_unique` — a 500 for a customer who had paid ten shillings a
moment earlier (production, 2026-08-13, receipt `UHDI92J1YN`). `issue()` now looks first and
catches `UniqueConstraintViolationException` as well: the check answers a repeat callback
cheaply, and the index arbitrates the genuine race, with the loser reading back the winner's
row. `CallbackProcessor::grantAccess()` also takes a `payments:grant:{id}` lock and re-reads
behind it — the lock keeps the ordinary case out of the race, and the idempotency covers what a
lock on one web server cannot promise about the next.

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

### An ISP gives us a till, not a Daraja app

Most ISPs have a till and nothing else. Obtaining consumer keys and a passkey is a fortnight of
Safaricom paperwork, and the ones who manage it usually arrive with something pasted into the
wrong field. So **the platform's Daraja app signs, and the ISP supplies only where the money
lands** — `DarajaCredentials::resolve()` builds an unsaved configuration carrying the platform's
credentials with the ISP's number as `PartyB`.

Daraja separates the two: `BusinessShortCode` is who the request authenticates as, `PartyB` is
who gets paid. That split is the whole arrangement, and the schema already had both.

- **Safaricom will not route to a `PartyB` the signing shortcode is not authorised for.** That
  is their rule, not a limitation here. Running this as a platform means holding the commercial
  arrangement with Safaricom that links ISPs' tills to the head-office shortcode; without it
  Daraja refuses the push and the refusal lands on the payment row like any other.
- An ISP that already holds its own Daraja app **keeps using it** — their credentials sit on
  their own row and `resolve()` leaves them alone.
- Where the platform has no app either, the ISP is still asked for credentials. Accepting a till
  number with nothing to sign it with would fail every payment quietly.
- The callback still carries the **ISP's** token, so a result signed by the platform finds the
  ISP it belongs to.
- Paystack is untouched by this: an ISP using it brings their own account, as before.

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
  up stranded on a page. The visible "Connect now" link stays regardless, for a phone that
  drifted onto mobile data for the M-PESA prompt.
- **That navigation is driven by a dispatched event, never by a `<script>` in the panel.** The
  success panel arrives through a `wire:poll` update, which *patches* the DOM — and Livewire
  does not execute a script tag introduced by a patch. So the automatic hop fired only on a
  full page load, which is precisely what a customer sitting on the payment screen is not
  doing: they watched a page carrying their code and nothing happened, indistinguishable from
  the purchase failing. `announceConnection()` dispatches `portal-connect`; an `@script` block
  registers the listener when the component first initialises, long before anyone has paid.
  `signInLink()` builds the URL once, for both the anchor and the event — two constructions of
  one URL is how those quietly stop agreeing.
- Paid but no login yet means the router was unreachable when the money landed. The portal keeps
  polling and retries issuing it rather than making anyone pay twice.

### Paid, and still not online

A **form, not a link that opens one** (`resources/views/portal/recover.blade.php`, included under
the packages and again on the failure screen). Whoever needs it has no internet and has already
paid; making them find and tap something before they can start typing is one obstacle too many
at the worst possible moment.

Money moves and then something goes wrong between there and the customer being connected: the
router was unreachable, the phone hopped to mobile data for the M-PESA prompt and never came
back, the tab was closed, the package was bought at the counter. In every one of them the
customer holds a confirmation SMS and has no idea which happened — nor should they need one.
So the portal's `recover()` asks for **the code and nothing else**, and tries each cause in turn:

- a `HotspotUser` already issued under that code — re-pushed if it is not live on the device;
- a `Payment` settled but never provisioned — `grantAccess()` runs now;
- a `Payment` still pending — `PaymentReconciler` asks the provider on the spot.

A findable payment that is not access reports **its own outcome** rather than "code not found":
telling somebody whose prompt was cancelled to check for a typo sends them hunting for one that
is not there. Expired packages are never resurrected.

Scoped to `router_id`, not merely to the tenant. A code bought at another branch would be found
and would then fail to let them on, which is worse than saying no.

The code is normalised (trim, uppercase) **before** validation — a page that answers a copied SMS
with a complaint about punctuation is arguing with a customer who has done nothing wrong.
`RateLimiter` caps attempts per router per IP; ten-character codes are not guessable, but an
unmetered lookup on a public page is still unmetered work.

`#[Computed] access()` resolves from either `accessId` (recovered) or the payment's
`hotspotUser`, so one success screen serves both — from where the customer stands they are the
same situation. **"Connect now" is withheld until the access is `Active`**: sending somebody to a
login the router has never heard of hands them a refusal for something that is about to work.
The code itself is always shown, because it is theirs and the SMS has it anyway.

### Scheduled work (`routes/console.php`)

- `hotspot:sweep --retry` every minute — removes spent packages, and re-pushes ones that were paid
  for while a router was down. A package that expired while stuck is *not* resurrected.
- `payments:reconcile` every minute — asks each provider what became of payments still waiting,
  for the customers who paid and then closed the page.
- `routers:watch` every minute — device health, outages, compensation and re-login (see
  above). Needs a queue worker in production (`isp-queue.service`). `router:test` remains as
  the terminal diagnostic.

The scheduler must actually be running: `php artisan schedule:work`.

**Every overlap guard carries an explicit expiry, and the number is load-bearing.**
`withoutOverlapping()` takes a lock before a task and releases it after; a task that is *killed*
rather than finished — a deploy restarting the scheduler, an OOM, a reboot — never reaches the
release, and the lock sits there for the default **24 hours**. The scheduler then reports "no
scheduled commands are ready to run" every minute while packages never expire and payments are
never chased. Nothing errors, nothing alerts; the work simply stops.

Seen in production on 2026-08-13: three stuck locks, all three per-minute tasks skipped for
about half an hour after a deploy restarted the scheduler mid-run. Two defences now:

- expiries of a few multiples of each task's interval (10 / 10 / 15 / 60 minutes), so a killed
  run heals itself;
- `schedule:clear-cache` in `deploy/release.sh` after the restart, so a deploy cannot wedge it
  at all. Safe because every scheduled task here is written to be run again.

`php artisan schedule:list` is the diagnosis: a task showing **"Has Mutex"** when it is not
currently running is a stuck lock.

### Production (`isp.solforbs.com`)

Ubuntu 24.04 at `178.162.241.172`, **shared** with `sms`, `panel`, `mail` and the apex site.
Nothing in a deploy may disturb those: the app gets its own nginx server block, its own MySQL
database and user, and its own Redis prefix and database numbers, and it changes nothing else.

- `/var/www/isp`, served by nginx over the shared `php8.3-fpm.sock` pool. Code is `root:www-data`
  and read-only to the web user; only `storage` and `bootstrap/cache` are writable by it, and
  `.env` is `640 root:www-data`.
**The server pulls a commit from GitHub.** It used to receive a tar of somebody's working
tree, and the trouble with that was not that it failed — it worked for months — but that what
ran could not be traced to anything. It was one laptop at one moment, including whatever was
uncommitted, and answering "what is live" meant hashing every file on both sides.

- `deploy/provision.sh` takes a bare Ubuntu box to a working one: packages, database, deploy
  key, clone, `.env`, WireGuard interface, nginx, systemd units, first release. Idempotent, so
  a run that stopped halfway is fixed by running it again. It stops short of the certificate
  (needs DNS) and the first administrator (asks for a password rather than knowing one).
- `deploy/certificate.sh` issues the certificate once DNS resolves, then swaps nginx onto the
  committed server block. It checks the name resolves *first*: Let's Encrypt rate-limits
  failures, and a name that does not resolve fails every time. `--webroot`, never `--nginx` —
  the nginx plugin rewrites the server block, and that block is committed, so a renewal three
  months from now would be silently undone by the next release.
- `deploy/release.sh` runs on the server: fetch, check out `REF` (default `main`, but a tag or
  an older commit for a rollback), then build. It **refuses to run if the checkout is dirty** —
  local edits on a server are either an emergency somebody forgot to commit or a mistake, and
  discarding them silently turns the first into the second.
- `deploy/push.sh` is the entry point from the development machine. Its only real job is
  refusing to deploy a commit that has not been pushed, which is the mistake people actually
  make: commit, deploy, wonder why nothing changed.
- `deploy/verify.sh` answers "what is running" with a commit, whether that commit is one this
  repository has, whether anything was edited on the server, and whether the services are up.

`.env` is written once by `provision.sh` and never touched again. It is the one file on that
server this repository does not own, and a release that rewrote it would take the database
password with it.
- `isp-scheduler.service` and `isp-queue.service` are systemd units, both `Restart=always`. The
  scheduler is not optional: expiry, `payments:reconcile` and router health all ride on it.
- **`APP_TIMEZONE=Africa/Nairobi`** in production, UTC locally and in tests. A payment stamped
  three hours off is one an operator cannot reconcile against an M-PESA statement.
- `php artisan platform:admin` creates the super admin, interactively — it asks for the password
  rather than knowing it.
- **`DatabaseSeeder` does not honour that.** It creates `admin@isp.solforbs.com` with the
  password `password` and `is_super_admin = true`, *before* its `app()->isLocal()` guard, so the
  account is seeded in every environment. Production has never had it only because
  `release.sh` runs `migrate --force` with no `--seed` — which is a convention holding the door
  shut, not a lock. Anyone running `migrate --seed` on the server opens it.
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
- **Wipe and rebuild is the default** (`wipe`, keeping a configuration has to be chosen). The
  paste fetches the full configuration (onto `flash/` where the board has one) and resets
  through a stored `/system/script`, because typed at the terminal `reset-configuration`
  stops to ask "Reset anyway?" and the paste then appears to do nothing.
- **The way in is built first** (production, 2026-10-15: a wiped RB951 came back invisible
  to Winbox). `resetPreamble()` opens with the ISP administrator, MAC Winbox and neighbour
  discovery on **all** interfaces, and a `bridge-tech` technician bridge on every spare
  ethernet port with DHCP at 192.168.88.1 (the next free /24 if the HotSpot uses it). Only
  then the uplink, tunnel and HotSpot, any of which may fail on some board. Nothing in the
  file uses `:error`; a missing port is logged.
- **The paste is one `{ }` block with pre-flight checks** (WAN port exists and has link,
  the platform's host resolves, the download arrives). Pasted line by line, a failed check
  printed its message and RouterOS carried on to the next line — including the reset.
- **After the preamble, every section of the first-boot file is its own deferred block**
  (tunnel, API user, HotSpot, HotSpot login pages, wifi, PPPoE), so one failure is logged as
  `platform-setup: <section> skipped or failed` and the rest still runs. Production,
  2026-10-15: the file stopped at `/ip/firewall/mangle add … place-before=0` — no rule 0 on a
  wiped router — leaving no API user, no HotSpot and no wifi. `place-before` is now only used
  when the list has rules. Deferred blocks drop comment lines and are kept under ~3 KB each,
  because older RouterOS caps a string near 4 KB.
- **`config_downloaded_at` and `hotspot_ready_at`** record onboarding for good
  (`provision_fetched_at` is cleared with the next link). The setup page's Progress panel
  polls `checkProgress()`, which runs the watcher at most every 15 s; the provisioning
  endpoint also queues a watch 100 s after the download. `SetupFinisher` (called by the
  watcher) confirms the HotSpot or builds it over the API with the remembered options, once
  per 15 minutes, only for routers built by the platform's wipe.
- **Commands for menus a board may lack go through `deferred()`** (`[[:parse "…"]]`):
  `/interface/wifi`, `/interface/wireless`, NTP servers, the uplink. A run-after-reset file
  is read whole before it runs, and one unrecognised command anywhere stops all of it —
  the router comes back blank with its radio off.
- A reset puts `admin` back on the factory password (blank on the RB951) and leaves no
  firewall, so the preamble also must:
  - bring up the uplink as DHCP, a static address or a PPPoE client (`wan_mode`); `uplink()`
    refuses an incomplete one *before* a link is issued, because that router comes back
    unreachable;
  - create `WAN` and `LAN` (`include=all exclude=WAN`) lists, drop input from `!LAN`, drop new
    forwarded traffic from WAN that was not dst-natted, and hold MAC server, MAC Winbox and
    neighbour discovery to LAN — layer-2 access never meets the firewall;
  - close Winbox and SSH to the customer address ranges, so a paying HotSpot user is never
    offered a login;
  - disable `admin` and create `routers.admin_username` (`isp-admin`) with a generated
    password, kept encrypted and stable across renders. The setup page shows it only on
    request, and that is audited. The upstream PPPoE password is encrypted on
    `routers.wan_pppoe_password`, never in `setup_options`.
  It waits on conditions (interfaces present, an active default route, the endpoint
  resolving) rather than fixed delays, and writes `platform-setup:` lines to the router's log
  — nobody can watch a run-after-reset script, so the log is the only account of it.
- `wireguard:peers` renders the other half. WireGuard is mutual: a router that knows about us
  gets nowhere unless the server knows about it too. It prints rather than applies, because
  rewriting a live tunnel is not a side effect a report should have.

On the production box: `wg0` at `10.8.0.1/24`, `wg-quick@wg0` enabled, UDP 51820 open in ufw,
and `WIREGUARD_PUBLIC_KEY` in the app's environment. **Management only** — no forwarding and no
NAT, so the tunnel never becomes an ISP's accidental transit provider.

### The same arrangement makes a bench router unreachable

Both of the things that make this work in a shop break it on a desk, and neither announces
itself. Two settings exist for that, both off by default, and `deploy/release.sh` **refuses to
release** if either is set on a server — the realistic way they get there is somebody copying
`.env` from a laptop.

- **`wireguard.manage_over_tunnel`** (default true). Generating a setup script repoints the
  router record at its tunnel address, because from then on that is where it answers. On a
  machine with no WireGuard server, `10.8.0.x` is an address to nowhere — and this runs on
  every *render* of the setup page rather than on a button, so a developer who merely looked at
  the script found their bench router timing out against a host they never typed.
- **`wireguard.local_api_subnet`** (default empty). The script binds the API to the management
  subnet and drops it everywhere else, so a laptop on the LAN is dropped too — which presents
  as a *timeout* rather than a refusal, because the packets are dropped rather than rejected.
  Setting this lets one more subnet in, in all three places that must agree: the `/ip/service`
  binding, the user's `address=`, and the firewall. Granting one and forgetting another gives a
  device that refuses differently each time and looks broken rather than closed.

The value is written straight into a firewall rule, so anything that is not plainly an IPv4
address or range is **refused rather than sanitised** — a typo quietly becoming `0.0.0.0/0`
would open the API to everyone on the strength of a misplaced character. The script also emits
`remove [find comment="platform-api-allow-local"]` unconditionally, so regenerating in
production revokes a bench allowance granted earlier.

Both are pinned to their production defaults in `phpunit.xml`. A laptop that set them was
quietly running a different suite from CI, which is how people learn to distrust the tests
rather than the setting.

## PPPoE billing and SMS

A PPPoE line is billed by its **paid-until date** (`service_accounts.expires_at`), set when the
line is created or under the row's **Package & paid until** menu on the customer page. Whole
days and months run to the end of the day, so a line due "on the 5th" works all of the 5th.

`pppoe:billing` runs every five minutes (`PppoeBillingRun`, per tenant):

- **Reminders**: one SMS `remind_days_before` days ahead (default 3, Settings → SMS) and one
  on the due date. Each is remembered against the expiry date it was for
  (`reminded_before_for` / `reminded_due_for`), so it goes once per cycle however often the
  run fires. Texts only go 08:00–20:00 Nairobi time.
- **Cut at expiry**: an active line past its date is suspended through
  `ChangeServiceAccountStatus` (secret disabled, session dropped) with
  `suspended_for_nonpayment = true`, and texted, at night deferred to the morning. Cutting
  does not wait for daytime.
- **Catch-up**: a paid renewal whose `applied_at` is still null (router down, process killed)
  is applied.

**Only a line the system cut is reconnected by a payment.** One an operator suspended by hand
stays suspended. A `Pending` line goes live with its first payment.

**Paying.** Every reminder carries `ServiceAccount::payUrl()`, the public `pay/{token}` page
(unauthenticated like the portal: the token resolves the line and tenant, `hydrate()` rebinds).
It sends an STK prompt through `PaymentCollector::collectRenewal()`, the ISP's own provider,
so the money lands where every other payment to that ISP does. The operator can also send the
prompt, a reminder, or record cash (`provider = 'manual'`, `payments.record`) from the
customer page. `CallbackProcessor::grantAccess()` sends any payment with a
`service_account_id` to `ServiceAccountRenewal::renew()` instead of issuing a HotSpot login.
`renew()` is idempotent (`applied_at`, a cache lock, a row lock): it extends from the current
expiry if still running, else from now, reconnects, and texts a receipt.

**Invoices** (`customer_invoices`, `CustomerInvoices`): what a subscriber owes their ISP, one
row per line per due date (unique index, so the run can raise it every five minutes and get
the same row). Numbered `INV-000001` per ISP by `SequenceGenerator`. Separate from
`tenant_invoices`, which is the ISP's bill from the platform.

- Raised by `pppoe:billing` when a line enters its reminder window, so the reminder can name
  it. `Pending` turns `Overdue` once the due date passes.
- `ServiceAccountRenewal` settles the oldest outstanding one with the payment, the receipt and
  the period actually bought. A payment with nothing outstanding (cash, paying early) leaves a
  **paid invoice behind as the receipt**, so every shilling has a document.
- Moving paid-until forward by hand voids what was outstanding before the new date. Operators
  with `invoices.void` can cancel one from the customer page.
- **Subscribers never sign in.** Invoices open at `pay/{token}/invoices/{number}`
  (`CustomerInvoiceController`, printable, "Print or save as PDF"). The number must belong to
  the line the token opens. The pay page lists the last twelve, reminders name the invoice,
  and the payment text links the receipt.
- **PDF and email** (`barryvdh/laravel-dompdf`): `InvoicePdf` renders
  `resources/views/pdf/customer-invoice.blade.php`, plain CSS 2.1 with tables (dompdf has no
  flex, grid or Tailwind) and DejaVu Sans, with font subsetting on (860 KB to about 23 KB).
  Downloaded at `pay/{token}/invoices/{number}/pdf`. `SendCustomerInvoice` emails it as an
  attachment when an invoice is raised, when one is paid (the receipt), and from the Email
  button on the customer page. Only to customers with an email address, and stamped
  `emailed_at`. It is a job carrying ids that binds the ISP and builds the mailable inside,
  rather than a queued mailable, because a queued mailable re-reads its models in the worker
  with no tenant bound, which strict scoping refuses. Dispatched `afterCommit()`, since
  invoices are raised and settled inside transactions. Sent from `MAIL_FROM_ADDRESS` under
  the ISP's name, with replies to the ISP's `contact_email`.

**Subscribers have their own account page** at `my/{tenant:slug}` (`pages::subscriber.login`,
`.dashboard`, `SubscriberAccess`). Under the ISP's address because phone numbers and PPPoE
logins are only unique within an ISP. Subscribers are not `User`s and get no password of
their own: they sign in with a six-digit SMS code to the phone on their customer record (ten
minutes, five tries, hashed in the cache, one use; the page never says whether a number is
registered) or with their PPPoE username and password. The session holds only the tenant and
customer ids and is re-read on every request, so the ISP is rebound with no middleware, as on
the portal. Only customers with a non-terminated PPPoE line get in. Rate limited per address.

The dashboard shows, per line: **connection** (`SubscriberConnection`, `/ppp/active` and the
`<pppoe-name>` interface's bytes, cached 20 s; an open outage answers without asking the
router), **billing** (paid-until, the pay link, invoices with PDFs, recent payments) and
**credentials** (username, password only after Show, the PPPoE service name). The customer
page and the pay page link to it.

**SMS is HostPinnacle** (`itsmurumba/laravel-hostpinnacle`, SaaS mode left off) behind
`SmsSender`. The ISP's own account (`sms_settings`, password and API key encrypted, sender ID
theirs) is used first, then the platform's (`HOSTPINNACLE_*` in the environment), else the
message is recorded `skipped`. Every attempt is an `sms_messages` row, shown on Settings → SMS.
`SmsSender` never throws: texting is a courtesy around billing, never a reason a renewal fails.

**Till-only M-PESA** (`DarajaCredentials`) now signs with the platform's Daraja app from
Platform admin → Platform payments (`PlatformTill::configuration()`, environment as
fallback), not `config('platform.mpesa.*')`. Safaricom still has to authorise the ISP's till
as `PartyB` for the platform's shortcode; until it does, those ISPs use their own Daraja keys
or Paystack.

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

**The platform's own collection is set in the dashboard** (Platform admin → Platform payments,
`platform_payment_settings`, one row, secrets encrypted and write-only). M-PESA (Daraja, paybill
or till), Paystack, or both, with a preferred one. The environment (`PLATFORM_MPESA_*`,
`PLATFORM_PAYSTACK_*`) is only a fallback. An ISP picks any unpaid invoice on Subscription,
chooses the provider and gets a prompt; nothing an ISP sees ever names a setting or a file.

Originally the platform's own M-PESA credentials lived in `config/platform.php` from the environment, not
in `mpesa_configurations` — there is one set for the whole installation, and it collects *from*
ISPs rather than for them. It is handed to the proven Daraja gateway as an unsaved
`MpesaConfiguration`, and it has its own callback route and token because the tenant callback
looks payments up in a table this money never touches.

## The manual

`resources/views/pages/docs/index.blade.php` with its chapters in `resources/views/docs/sections/`
— outside the `pages::` namespace, so Livewire never tries to treat a partial as a component.
Linked from the bottom of the sidebar, where the starter kit's links to Laravel's own docs used
to be.

- **In the application, not on a documentation site.** Most of it is read while a router is down,
  which is exactly when leaving here is least useful.
- **Outside `subscribed`**, like the billing screen: half of it is read by somebody trying to put
  a lapsed account back in order.
- The chapter id is interpolated into a view name and arrives from the query string, so the
  include uses `current()['id']` — which falls back to the first chapter — and never `$section`.
- Prices come from `config/platform.php`, so what the manual quotes and what the system bills
  cannot drift apart.

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
