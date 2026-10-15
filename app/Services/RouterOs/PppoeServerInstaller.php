<?php

namespace App\Services\RouterOs;

use App\Enums\PlanType;
use App\Models\Plan;
use App\Models\Router;
use App\Services\RouterOs\Contracts\RouterOsConnection;
use App\Services\RouterOs\Contracts\RouterOsConnector;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use Throwable;

/**
 * Puts a PPPoE server on a router the platform can already reach — what the setup script's
 * PPPoE section does when pasted, done over the API.
 *
 * Without a server, the PPPoE secrets the platform writes for every subscriber authenticate
 * nothing: there is nobody on the wire asking for them. This makes the router answer PPPoE on
 * the chosen interface, hand each subscriber an address from a pool, and route them out.
 *
 * The per-package profiles the secrets point at are pushed again at the end, because they
 * take their addresses from what this builds (`PlanProfileSynchroniser::pppProfile()`). A line
 * on a profile with no remote address authenticates and then gets no IP — which looks to the
 * subscriber like a connection that never finishes.
 *
 * Re-runnable, like HotSpot setup: each step replaces what the last run made by name or
 * `platform-pppoe` comment rather than adding a second.
 */
class PppoeServerInstaller
{
    public const PROFILE = 'platform-pppoe';

    public const COMMENT = 'platform-pppoe';

    public function __construct(
        protected RouterOsConnector $connector,
        protected AuditLogger $audit,
        protected PlanProfileSynchroniser $profiles,
    ) {}

    /**
     * @param  array{interface: string, address: string, pool: string, dns_servers: string, service_name: string, masquerade: bool}  $setup
     * @return array<int, array{step: string, status: string, detail: string}>
     */
    public function install(Router $router, array $setup): array
    {
        $plan = $this->plan($router, $setup);
        $report = [];

        try {
            $connection = $this->connector->connect($router);
        } catch (Throwable $e) {
            return [['step' => __('Connect to the router'), 'status' => HotspotInstaller::STEP_FAILED, 'detail' => $e->getMessage()]];
        }

        $core = [
            __('PPPoE interface') => fn () => $this->checkInterface($connection, $router, $plan),
            __('Address pool') => fn () => $this->pool($connection, $plan),
            __('Default PPP profile') => fn () => $this->profile($connection, $plan),
            __('PPPoE server') => fn () => $this->server($connection, $plan),
            __('Masquerade network') => fn () => $this->masquerade($connection, $plan),
        ];

        $failed = false;

        foreach ($core as $step => $run) {
            if ($failed) {
                $report[] = ['step' => $step, 'status' => HotspotInstaller::STEP_SKIPPED, 'detail' => __('Skipped: an earlier step failed.')];

                continue;
            }

            $report[] = $this->step($step, $run);
            $failed = end($report)['status'] === HotspotInstaller::STEP_FAILED;
        }

        $connection->disconnect();

        if (! $failed) {
            $this->remember($router, $plan);
            $report[] = $this->step(__('Package profiles'), fn () => $this->packages($router));
        }

        $this->audit->record(
            event: $failed ? 'router.pppoe_setup_failed' : 'router.pppoe_installed',
            auditable: $router,
            description: sprintf(
                '%s PPPoE server "%s" on %s (%s, %s)',
                $failed ? 'Could not set up' : 'Set up',
                $plan['service_name'],
                $plan['interface'],
                $plan['address'],
                $router->name,
            ),
            newValues: ['setup' => $plan, 'report' => $report],
        );

        return $report;
    }

    /**
     * Everything the steps need, worked out and checked once.
     *
     * @param  array{interface: string, address: string, pool: string, dns_servers: string, service_name: string, masquerade: bool}  $setup
     * @return array{interface: string, address: string, gateway: string, network: string, prefix: int, pool: string, pool_name: string, dns_servers: string, service_name: string, masquerade: bool}
     *
     * @throws InvalidArgumentException
     */
    public function plan(Router $router, array $setup): array
    {
        $interface = $this->name($setup['interface'], '');

        if ($interface === '') {
            throw new InvalidArgumentException(__('Choose the interface subscribers connect on.'));
        }

        if (! preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', trim($setup['address']), $match)) {
            throw new InvalidArgumentException(__('The local address needs its prefix, e.g. 10.20.0.1/24.'));
        }

        [$gateway, $prefix] = [$match[1], (int) $match[2]];

        $public = filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;

        if (filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || $public || $prefix < 16 || $prefix > 30) {
            throw new InvalidArgumentException(__('Use a private address with a prefix between /16 and /30.'));
        }

        $mask = -1 << (32 - $prefix);
        $network = long2ip(ip2long($gateway) & $mask).'/'.$prefix;
        $options = $router->setupOptions();

        // Two networks handing out the same addresses route each other's traffic into the
        // wrong place, and both look configured.
        foreach (['HotSpot' => $options['hotspot_network'] ?? null, 'management' => '10.8.0.0/24'] as $label => $other) {
            if (is_string($other) && $other !== '' && $this->overlaps($network, $other)) {
                throw new InvalidArgumentException(__(':network overlaps the :label network (:other). Choose another range, e.g. 10.20.0.1/24.', ['network' => $network, 'label' => $label, 'other' => $other]));
            }
        }

        $pool = trim($setup['pool']) !== '' ? trim($setup['pool']) : HotspotInstaller::defaultPool($gateway, $prefix);

        if (! preg_match('/^\d{1,3}(\.\d{1,3}){3}-\d{1,3}(\.\d{1,3}){3}(,\d{1,3}(\.\d{1,3}){3}-\d{1,3}(\.\d{1,3}){3})*$/', $pool)) {
            throw new InvalidArgumentException(__('The address pool is a range, e.g. 10.20.0.10-10.20.0.254.'));
        }

        $dns = collect(explode(',', $setup['dns_servers']))
            ->map(fn (string $server): string => trim($server))
            ->filter(fn (string $server): bool => filter_var($server, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false)
            ->implode(',');

        return [
            'interface' => $interface,
            'address' => $gateway.'/'.$prefix,
            'gateway' => $gateway,
            'network' => $network,
            'prefix' => $prefix,
            'pool' => $pool,
            // The setup script's name for it, so a pasted script and this agree on one pool.
            'pool_name' => $this->name((string) ($options['pppoe_pool_name'] ?? (($options['hotspot_name'] ?? 'billing-hotspot').'-pppoe-pool')), 'pppoe-pool'),
            'dns_servers' => $dns !== '' ? $dns : '1.1.1.1,8.8.8.8',
            'service_name' => $this->name($setup['service_name'], 'isp'),
            'masquerade' => $setup['masquerade'],
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    protected function checkInterface(RouterOsConnection $connection, Router $router, array $plan): string
    {
        $options = $router->setupOptions();

        if ($plan['interface'] === ($options['wan_interface'] ?? null)) {
            throw new InvalidArgumentException(__(':interface is this router\'s uplink. Subscribers connect on a customer port.', ['interface' => $plan['interface']]));
        }

        if ($plan['interface'] === ($options['interface_name'] ?? null)) {
            throw new InvalidArgumentException(__(':interface is the management tunnel.', ['interface' => $plan['interface']]));
        }

        if ($plan['interface'] === 'bridge-tech') {
            throw new InvalidArgumentException(__('bridge-tech is the technician port, kept free so the router can always be reached. Choose the customer port instead.'));
        }

        $row = $connection->first('/interface', ['name' => $plan['interface']]);

        if ($row === null) {
            throw new InvalidArgumentException(__('There is no interface called :interface on this router.', ['interface' => $plan['interface']]));
        }

        // PPPoE discovery is layer 2: on a bridge member it is the bridge that hears it.
        $port = $connection->first('/interface/bridge/port', ['interface' => $plan['interface']]);

        if ($port !== null) {
            throw new InvalidArgumentException(__(':interface is a port of :bridge. Choose :bridge instead.', ['interface' => $plan['interface'], 'bridge' => $port['bridge'] ?? 'its bridge']));
        }

        foreach ($connection->get('/interface/pppoe-server/server', ['interface' => $plan['interface']]) as $server) {
            if (($server['service-name'] ?? '') !== $plan['service_name']) {
                throw new InvalidArgumentException(__('A PPPoE server called ":name" already runs on :interface. Use that service name, or remove it first in the console.', ['name' => $server['service-name'] ?? '?', 'interface' => $plan['interface']]));
            }
        }

        return __(':interface (:type)', ['interface' => $plan['interface'], 'type' => $row['type'] ?? 'interface']);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    protected function pool(RouterOsConnection $connection, array $plan): string
    {
        $existing = $connection->first('/ip/pool', ['name' => $plan['pool_name']]);

        $existing === null
            ? $connection->add('/ip/pool', ['name' => $plan['pool_name'], 'ranges' => $plan['pool']])
            : $connection->update('/ip/pool', $existing['.id'], ['ranges' => $plan['pool']]);

        return $plan['pool_name'].' · '.$plan['pool'];
    }

    /**
     * The server's fallback profile, for a secret that names none.
     *
     * @param  array<string, mixed>  $plan
     */
    protected function profile(RouterOsConnection $connection, array $plan): string
    {
        $attributes = [
            'local-address' => $plan['gateway'],
            'remote-address' => $plan['pool_name'],
            'dns-server' => $plan['dns_servers'],
            'only-one' => 'yes',
            'change-tcp-mss' => 'yes',
        ];

        $existing = $connection->first('/ppp/profile', ['name' => self::PROFILE]);

        $existing === null
            ? $connection->add('/ppp/profile', ['name' => self::PROFILE] + $attributes)
            : $connection->update('/ppp/profile', $existing['.id'], $attributes);

        return __(':profile: gateway :gateway, addresses from :pool', ['profile' => self::PROFILE, 'gateway' => $plan['gateway'], 'pool' => $plan['pool_name']]);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    protected function server(RouterOsConnection $connection, array $plan): string
    {
        foreach ($connection->get('/interface/pppoe-server/server', ['service-name' => $plan['service_name']]) as $row) {
            $connection->remove('/interface/pppoe-server/server', $row['.id']);
        }

        $connection->add('/interface/pppoe-server/server', [
            'service-name' => $plan['service_name'],
            'interface' => $plan['interface'],
            'default-profile' => self::PROFILE,
            'authentication' => 'pap,chap,mschap1,mschap2',
            'one-session-per-host' => 'yes',
            'disabled' => 'no',
        ]);

        return __('":name" on :interface', ['name' => $plan['service_name'], 'interface' => $plan['interface']]);
    }

    /**
     * PPPoE subscribers are routed rather than bridged, so their subnet needs a way out.
     *
     * @param  array<string, mixed>  $plan
     */
    protected function masquerade(RouterOsConnection $connection, array $plan): string
    {
        foreach ($connection->get('/ip/firewall/nat', ['comment' => self::COMMENT]) as $row) {
            $connection->remove('/ip/firewall/nat', $row['.id']);
        }

        if (! $plan['masquerade']) {
            return __('Not added. Subscribers reach the internet only if something else masquerades them.');
        }

        $connection->add('/ip/firewall/nat', [
            'chain' => 'srcnat',
            'src-address' => $plan['network'],
            'action' => 'masquerade',
            'comment' => self::COMMENT,
        ]);

        return $plan['network'];
    }

    /**
     * Push every PPPoE package again, now that there are addresses to give their profiles.
     */
    protected function packages(Router $router): string
    {
        $plans = Plan::query()->ofType(PlanType::Pppoe)->get();

        if ($plans->isEmpty()) {
            return __('No PPPoE packages yet. Create one under PPPoE → Packages.');
        }

        $failed = $plans
            ->map(fn (Plan $plan) => $this->profiles->sync($plan, $router))
            ->filter(fn ($pairing): bool => $pairing->sync_error !== null);

        if ($failed->isNotEmpty()) {
            throw new InvalidArgumentException(__('Could not update :count package profile(s): :error', ['count' => $failed->count(), 'error' => $failed->first()->sync_error]));
        }

        return trans_choice(':count package profile updated|:count package profiles updated', $plans->count());
    }

    /**
     * In the setup script's own terms, so a later wipe-and-rebuild builds the same server and
     * package profiles know where to take addresses from.
     *
     * @param  array<string, mixed>  $plan
     */
    protected function remember(Router $router, array $plan): void
    {
        $router->setup_options = array_merge($router->setup_options ?? [], [
            'with_pppoe' => true,
            'pppoe_interface' => $plan['interface'],
            'pppoe_service_name' => $plan['service_name'],
            'pppoe_network' => $plan['network'],
            'pppoe_gateway' => $plan['gateway'],
            'pppoe_pool' => $plan['pool'],
            'pppoe_pool_name' => $plan['pool_name'],
            'pppoe_dns_servers' => $plan['dns_servers'],
        ]);
        $router->save();
    }

    protected function overlaps(string $a, string $b): bool
    {
        [$aNet, $aBits] = array_pad(explode('/', $a), 2, '32');
        [$bNet, $bBits] = array_pad(explode('/', $b), 2, '32');

        $aLong = ip2long($aNet);
        $bLong = ip2long($bNet);

        if ($aLong === false || $bLong === false) {
            return false;
        }

        $bits = min((int) $aBits, (int) $bBits);
        $mask = -1 << (32 - $bits);

        return ($aLong & $mask) === ($bLong & $mask);
    }

    /**
     * @param  callable(): string  $run
     * @return array{step: string, status: string, detail: string}
     */
    protected function step(string $step, callable $run): array
    {
        try {
            return ['step' => $step, 'status' => HotspotInstaller::STEP_OK, 'detail' => $run()];
        } catch (Throwable $e) {
            return ['step' => $step, 'status' => HotspotInstaller::STEP_FAILED, 'detail' => $e->getMessage()];
        }
    }

    protected function name(string $value, string $fallback): string
    {
        $clean = (string) preg_replace('/[^A-Za-z0-9._-]/', '', $value);

        return $clean === '' ? $fallback : $clean;
    }
}
