<?php

namespace App\Services\RouterOs;

use App\Http\Controllers\HotspotPageController;
use App\Models\Router;
use App\Services\RouterOs\Contracts\RouterOsConnection;
use App\Services\RouterOs\Contracts\RouterOsConnector;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use Throwable;

/**
 * Builds a working HotSpot on a router the platform can already reach — Winbox's
 * "HotSpot Setup" wizard, done over the API instead of by hand.
 *
 * The same pieces the wizard creates, in the order it creates them: an address on the
 * interface, a pool, a DHCP server, DNS, a server profile, the HotSpot server itself, and
 * masquerade. Then what the wizard knows nothing about and this platform cannot work without:
 * the walled garden that lets an unpaid phone reach the portal and the payment providers, and
 * the login pages that hand customers to the portal instead of asking them for a password they
 * were never given.
 *
 * Every step is safe to run again. Re-running after changing the address range replaces what
 * the last run made rather than adding a second of everything, which is what makes the wizard
 * usable as "fix my HotSpot" and not only "create one".
 *
 * The core steps stop at the first failure: a DHCP server pointing at a pool that was never
 * created is worse than no DHCP server, because it looks configured. The walled garden and
 * the pages carry on regardless, since each is useful on its own and each says what failed.
 */
class HotspotInstaller
{
    public const STEP_OK = 'ok';

    public const STEP_FAILED = 'failed';

    public const STEP_SKIPPED = 'skipped';

    /** The bridge customers' ports and wifi share, matching the setup script's name for it. */
    public const BRIDGE = 'bridge-hotspot';

    public function __construct(
        protected RouterOsConnector $connector,
        protected AuditLogger $audit,
    ) {}

    /**
     * @param  array{interface: string, address: string, pool: string, dns_servers: string, dns_name: string, masquerade: bool, name: string, wifi_name?: string}  $setup
     * @return array<int, array{step: string, status: string, detail: string}>
     */
    public function install(Router $router, array $setup): array
    {
        $plan = $this->plan($router, $setup);
        $report = [];

        try {
            $connection = $this->connector->connect($router);
        } catch (Throwable $e) {
            return [['step' => __('Connect to the router'), 'status' => self::STEP_FAILED, 'detail' => $e->getMessage()]];
        }

        // Closures over $plan by reference, not arrow functions: the bridge step moves the
        // HotSpot from the chosen port onto the customer bridge, and every step after it has
        // to build on the bridge.
        $core = [
            __('HotSpot interface') => function () use ($connection, $router, &$plan) {
                return $this->checkInterface($connection, $router, $plan);
            },
            __('Previous HotSpot taken down') => function () use ($connection, &$plan) {
                return $this->clearPrevious($connection, $plan);
            },
            __('Customer bridge') => function () use ($connection, &$plan) {
                return $this->bridge($connection, $plan);
            },
            __('Wi-Fi') => function () use ($connection, &$plan) {
                return $this->wifi($connection, $plan);
            },
            __('Local address of network') => function () use ($connection, &$plan) {
                return $this->address($connection, $plan);
            },
            __('Address pool') => function () use ($connection, &$plan) {
                return $this->pool($connection, $plan);
            },
            __('DHCP server') => function () use ($connection, &$plan) {
                return $this->dhcp($connection, $plan);
            },
            __('DNS servers') => function () use ($connection, &$plan) {
                return $this->dns($connection, $plan);
            },
            __('HotSpot server profile') => function () use ($connection, &$plan) {
                return $this->profile($connection, $plan);
            },
            __('HotSpot server') => function () use ($connection, &$plan) {
                return $this->server($connection, $plan);
            },
            __('Masquerade network') => function () use ($connection, &$plan) {
                return $this->masquerade($connection, $plan);
            },
        ];

        $failed = false;

        foreach ($core as $step => $run) {
            if ($failed) {
                $report[] = ['step' => $step, 'status' => self::STEP_SKIPPED, 'detail' => __('Skipped: an earlier step failed.')];

                continue;
            }

            $report[] = $this->step($step, $run);
            $failed = end($report)['status'] === self::STEP_FAILED;
        }

        if (! $failed) {
            $report[] = $this->step(__('Walled garden (portal and payments)'), fn () => $this->walledGarden($connection, $plan));
            $report[] = $this->step(__('Fasttrack removed'), fn () => $this->noFasttrack($connection));
            $report[] = $this->step(__('Login pages'), fn () => $this->pages($connection, $router));
        }

        $connection->disconnect();

        if (! $failed) {
            $this->remember($router, $plan);
        }

        $this->audit->record(
            event: $failed ? 'router.hotspot_setup_failed' : 'router.hotspot_installed',
            auditable: $router,
            description: sprintf(
                '%s HotSpot "%s" on %s (%s, %s)',
                $failed ? 'Could not set up' : 'Set up',
                $plan['name'],
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
     * @param  array{interface: string, address: string, pool: string, dns_servers: string, dns_name: string, masquerade: bool, name: string, wifi_name?: string}  $setup
     * @return array{interface: string, port: string, address: string, gateway: string, network: string, prefix: int, pool: string, pool_name: string, previous_interface: string, dns_servers: string, dns_name: string, masquerade: bool, name: string, profile: string, dhcp: string, wifi_name: string}
     *
     * @throws InvalidArgumentException
     */
    public function plan(Router $router, array $setup): array
    {
        $interface = $this->name($setup['interface']);

        if (! preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', trim($setup['address']), $match)) {
            throw new InvalidArgumentException(__('The local address needs its prefix, e.g. 10.10.10.1/24.'));
        }

        [$gateway, $prefix] = [$match[1], (int) $match[2]];

        // A public range here would hand customers addresses that belong to somebody else on
        // the internet, and route their traffic for those addresses into the HotSpot instead.
        $public = filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;

        if (filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || $public || $prefix < 16 || $prefix > 30) {
            throw new InvalidArgumentException(__('Use a private address with a prefix between /16 and /30.'));
        }

        $mask = -1 << (32 - $prefix);
        $network = long2ip(ip2long($gateway) & $mask).'/'.$prefix;

        $pool = trim($setup['pool']) !== '' ? trim($setup['pool']) : self::defaultPool($gateway, $prefix);

        if (! preg_match('/^\d{1,3}(\.\d{1,3}){3}-\d{1,3}(\.\d{1,3}){3}(,\d{1,3}(\.\d{1,3}){3}-\d{1,3}(\.\d{1,3}){3})*$/', $pool)) {
            throw new InvalidArgumentException(__('The address pool is a range, e.g. 10.10.10.10-10.10.10.254.'));
        }

        $dns = collect(explode(',', $setup['dns_servers']))
            ->map(fn (string $server): string => trim($server))
            ->filter(fn (string $server): bool => filter_var($server, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false)
            ->implode(',');

        $dnsName = trim($setup['dns_name']);

        if ($dnsName !== '' && ! preg_match('/^[A-Za-z0-9]([A-Za-z0-9\-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9\-]*[A-Za-z0-9])?)+$/', $dnsName)) {
            throw new InvalidArgumentException(__('The DNS name is a hostname like wifi.myisp.co.ke, or empty.'));
        }

        $name = $this->name($setup['name'] ?: 'billing-hotspot');
        $options = $router->setupOptions();

        return [
            'interface' => $interface,
            // What was chosen, kept apart from where the HotSpot ends up: with Wi-Fi the HotSpot
            // runs on the customer bridge, and the port is one member of it.
            'port' => $interface,
            'wifi_name' => self::wifiName((string) ($setup['wifi_name'] ?? '')),
            'address' => $gateway.'/'.$prefix,
            'gateway' => $gateway,
            'network' => $network,
            'prefix' => $prefix,
            'pool' => $pool,
            // The pool the setup script made, when there was one: replacing it by name is
            // what keeps a re-run from leaving the old one behind, unused.
            'pool_name' => $this->name((string) ($options['pool_name'] ?? $name.'-pool')),
            'previous_interface' => $this->name((string) ($options['lan_interface'] ?? $interface)),
            'dns_servers' => $dns !== '' ? $dns : '1.1.1.1,8.8.8.8',
            'dns_name' => $dnsName,
            'masquerade' => $setup['masquerade'],
            'name' => $name,
            'profile' => $name.'-profile',
            'dhcp' => 'dhcp-'.$name,
        ];
    }

    /**
     * The usual pool for a gateway: clear of the gateway and of the first few addresses, so an
     * ISP has room for an access point or a printer with a fixed address.
     */
    public static function defaultPool(string $gateway, int $prefix): string
    {
        $long = ip2long($gateway);

        if ($long === false || $prefix < 16 || $prefix > 30) {
            return '';
        }

        $mask = -1 << (32 - $prefix);
        $first = ($long & $mask) + 1;
        $last = ($long | ~$mask) - 1;
        $start = max($first, min($last, $first + 9));

        // The gateway is never handed out. At either end of the range it is simply trimmed off;
        // in the middle the pool becomes two ranges around it, which RouterOS accepts, rather
        // than throwing away everything below it.
        if ($long < $start || $long > $last) {
            return long2ip($start).'-'.long2ip($last);
        }

        if ($long === $start) {
            return long2ip($start + 1).'-'.long2ip($last);
        }

        if ($long === $last) {
            return long2ip($start).'-'.long2ip($last - 1);
        }

        return long2ip($start).'-'.long2ip($long - 1).','.long2ip($long + 1).'-'.long2ip($last);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    protected function checkInterface(RouterOsConnection $connection, Router $router, array $plan): string
    {
        $options = $router->setupOptions();

        // The uplink in a HotSpot puts the router's own internet behind a login page, and the
        // platform with it. The tunnel is the platform's road in.
        if ($plan['interface'] === ($options['wan_interface'] ?? null)) {
            throw new InvalidArgumentException(__(':interface is this router\'s uplink. A HotSpot on it would put the router\'s own internet behind a login page.', ['interface' => $plan['interface']]));
        }

        if ($plan['interface'] === ($options['interface_name'] ?? null)) {
            throw new InvalidArgumentException(__(':interface is the management tunnel.', ['interface' => $plan['interface']]));
        }

        // The technician's way in. A HotSpot on it would put the one port that always works
        // behind a payment page.
        if ($plan['interface'] === 'bridge-tech') {
            throw new InvalidArgumentException(__('bridge-tech is the technician port, kept free so the router can always be reached. Choose the customer port instead.'));
        }

        $row = $connection->first('/interface', ['name' => $plan['interface']]);

        if ($row === null) {
            throw new InvalidArgumentException(__('There is no interface called :interface on this router.', ['interface' => $plan['interface']]));
        }

        // A port that is a member of a bridge does not carry an address of its own; the
        // bridge does. Building a HotSpot on the port would look right and hand out nothing.
        $port = $connection->first('/interface/bridge/port', ['interface' => $plan['interface']]);

        // Our own customer bridge is the exception: the port was put there by an earlier run,
        // and choosing it again means "this HotSpot", which the bridge step picks up.
        if ($port !== null && ($port['bridge'] ?? '') !== self::BRIDGE) {
            throw new InvalidArgumentException(__(':interface is a port of :bridge. Choose :bridge instead.', ['interface' => $plan['interface'], 'bridge' => $port['bridge'] ?? 'its bridge']));
        }

        return __(':interface (:type)', ['interface' => $plan['interface'], 'type' => $row['type'] ?? 'interface']);
    }

    /**
     * A wifi name as RouterOS and phones will take it: printable, no quotes, 32 bytes at most.
     * Empty means "leave the wifi alone".
     */
    public static function wifiName(string $value): string
    {
        $name = trim((string) preg_replace("/[^A-Za-z0-9 _\-.'&+()]/", '', $value));

        return mb_strimwidth($name, 0, 32);
    }

    /**
     * What the last run built comes down before anything is rebuilt, so moving the HotSpot
     * from a port onto the bridge never leaves a server or a DHCP server on the old port.
     *
     * @param  array<string, mixed>  $plan
     */
    protected function clearPrevious(RouterOsConnection $connection, array $plan): string
    {
        $this->dropDhcp($connection, $plan);

        return __('Nothing left over from before');
    }

    /**
     * Put the chosen port and the radio in one bridge, so cable and wifi customers are one
     * network behind one HotSpot.
     *
     * Only with a Wi-Fi name. Without one the HotSpot runs on the chosen interface alone, as
     * it always did. A board with no radio gets the same, with a line saying so.
     *
     * @param  array<string, mixed>  $plan
     */
    protected function bridge(RouterOsConnection $connection, array &$plan): string
    {
        if ($plan['wifi_name'] === '') {
            return __('Not needed: the HotSpot runs on :interface alone.', ['interface' => $plan['interface']]);
        }

        $radios = array_column($this->radios($connection)['rows'], 'name');

        if ($radios === []) {
            return __('This router has no wifi radio, so the HotSpot runs on :interface alone.', ['interface' => $plan['interface']]);
        }

        $chosen = $connection->first('/interface', ['name' => $plan['interface']]);
        $isBridge = ($chosen['type'] ?? '') === 'bridge';
        $bridge = $isBridge ? (string) $plan['interface'] : self::BRIDGE;

        if (! $isBridge && $connection->first('/interface/bridge', ['name' => $bridge]) === null) {
            $connection->add('/interface/bridge', ['name' => $bridge, 'comment' => 'HotSpot: customer ports and wifi']);
        }

        $members = $isBridge || $plan['interface'] === $bridge ? $radios : [$plan['interface'], ...$radios];

        foreach (array_unique($members) as $member) {
            if ($member === $bridge) {
                continue;
            }

            // Out of whatever bridge it was in first: a port in two bridges is not a thing
            // RouterOS allows, and the technician bridge must keep its own ports.
            foreach ($connection->get('/interface/bridge/port', ['interface' => $member]) as $row) {
                $connection->remove('/interface/bridge/port', $row['.id']);
            }

            $connection->add('/interface/bridge/port', ['bridge' => $bridge, 'interface' => $member]);
        }

        $plan['interface'] = $bridge;

        return __(':bridge: :members', ['bridge' => $bridge, 'members' => implode(', ', array_unique($members))]);
    }

    /**
     * Switch the radio on as an open network under the name given.
     *
     * Open on purpose: the HotSpot is what checks who someone is, and a wifi password would
     * turn a customer away before they could pay. Two radio stacks exist and their commands
     * differ; the router is asked which it has rather than told.
     *
     * @param  array<string, mixed>  $plan
     */
    protected function wifi(RouterOsConnection $connection, array $plan): string
    {
        if ($plan['wifi_name'] === '') {
            return __('Not changed: no Wi-Fi name given.');
        }

        ['stack' => $stack, 'rows' => $rows] = $this->radios($connection);

        if ($rows === []) {
            return __('This router has no wifi radio.');
        }

        if ($stack === 'wireless') {
            if ($connection->first('/interface/wireless/security-profiles', ['name' => 'platform-open']) === null) {
                $connection->add('/interface/wireless/security-profiles', ['name' => 'platform-open', 'mode' => 'none']);
            }

            foreach ($rows as $radio) {
                $connection->update('/interface/wireless', $radio['.id'], [
                    'ssid' => $plan['wifi_name'],
                    'mode' => 'ap-bridge',
                    'security-profile' => 'platform-open',
                    'disabled' => 'no',
                ]);
            }
        } else {
            foreach ($rows as $radio) {
                $connection->update('/interface/wifi', $radio['.id'], [
                    'configuration.ssid' => $plan['wifi_name'],
                    'configuration.mode' => 'ap',
                    'security.authentication-types' => '',
                    'disabled' => 'no',
                ]);
            }
        }

        return __('":name", open, on :radios', ['name' => $plan['wifi_name'], 'radios' => implode(', ', array_column($rows, 'name'))]);
    }

    /**
     * The radios this board has, and which RouterOS package drives them. A board lacks the
     * other package's menu entirely, which the API reports as an error — so each is tried.
     *
     * @return array{stack: string, rows: array<int, array<string, string>>}
     */
    protected function radios(RouterOsConnection $connection): array
    {
        foreach (['wireless' => '/interface/wireless', 'wifi' => '/interface/wifi'] as $stack => $menu) {
            try {
                $rows = $connection->get($menu);
            } catch (Throwable) {
                continue;
            }

            if ($rows !== []) {
                return ['stack' => $stack, 'rows' => $rows];
            }
        }

        return ['stack' => '', 'rows' => []];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    protected function address(RouterOsConnection $connection, array $plan): string
    {
        // Only the gateway this HotSpot had before — on this interface, or the one it is
        // moving from. Another HotSpot's gateway on another port is not ours to take away.
        foreach ($connection->get('/ip/address', ['comment' => 'HotSpot gateway']) as $row) {
            if (in_array($row['interface'] ?? '', [$plan['interface'], $plan['previous_interface'], $plan['port']], true)) {
                $connection->remove('/ip/address', $row['.id']);
            }
        }

        // Any other address in this network, on *any* interface. One network on two interfaces
        // is a router that cannot decide where to send its replies: the DHCP server and the
        // HotSpot go invalid and phones sit on "obtaining IP address" forever. Seen on a real
        // router, with the HotSpot's gateway left on the technician bridge.
        //
        // Except where the platform itself depends on that address: those are refused, so the
        // operator picks another network rather than losing the way in.
        foreach ($connection->get('/ip/address') as $row) {
            if (($row['dynamic'] ?? 'false') === 'true' || ($row['network'] ?? '').'/'.$plan['prefix'] !== $plan['network']) {
                continue;
            }

            if (in_array($row['comment'] ?? '', ['Technician access', 'Management address', 'Uplink'], true)) {
                throw new InvalidArgumentException(__(':network is already the router\'s :what network. Choose another local address, such as 10.10.10.1/24.', [
                    'network' => $plan['network'],
                    'what' => match ($row['comment']) {
                        'Technician access' => __('technician'),
                        'Management address' => __('management'),
                        default => __('internet'),
                    },
                ]));
            }

            $connection->remove('/ip/address', $row['.id']);
        }

        $connection->add('/ip/address', [
            'address' => $plan['address'],
            'interface' => $plan['interface'],
            'comment' => 'HotSpot gateway',
        ]);

        return $plan['address'];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    protected function pool(RouterOsConnection $connection, array $plan): string
    {
        $this->dropDhcp($connection, $plan);

        foreach ($connection->get('/ip/pool', ['name' => $plan['pool_name']]) as $row) {
            $connection->remove('/ip/pool', $row['.id']);
        }

        $connection->add('/ip/pool', ['name' => $plan['pool_name'], 'ranges' => $plan['pool']]);

        return $plan['pool'];
    }

    /**
     * RouterOS will not remove a pool, or an interface's address, while a DHCP server or a
     * HotSpot still uses it — so what the last run built comes down first, in reverse order.
     *
     * @param  array<string, mixed>  $plan
     */
    protected function dropDhcp(RouterOsConnection $connection, array $plan): void
    {
        foreach ($connection->get('/ip/hotspot', ['name' => $plan['name']]) as $row) {
            $connection->remove('/ip/hotspot', $row['.id']);
        }

        foreach ($connection->get('/ip/dhcp-server', ['name' => $plan['dhcp']]) as $row) {
            $connection->remove('/ip/dhcp-server', $row['.id']);
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    protected function dhcp(RouterOsConnection $connection, array $plan): string
    {
        // One DHCP server per interface is all RouterOS allows. Another one already there —
        // the stock defconf server on a bridge, say — has to be named rather than overridden.
        foreach ($connection->get('/ip/dhcp-server', ['interface' => $plan['interface']]) as $row) {
            if (($row['name'] ?? '') !== $plan['dhcp']) {
                throw new InvalidArgumentException(__('DHCP server ":name" already serves :interface. Remove it in the console (IP) or choose another interface.', ['name' => $row['name'] ?? '?', 'interface' => $plan['interface']]));
            }
        }

        foreach ($connection->get('/ip/dhcp-server/network', ['comment' => 'platform-hotspot']) as $row) {
            $connection->remove('/ip/dhcp-server/network', $row['.id']);
        }

        $connection->add('/ip/dhcp-server', [
            'name' => $plan['dhcp'],
            'interface' => $plan['interface'],
            'address-pool' => $plan['pool_name'],
            'lease-time' => '1h',
            'disabled' => 'no',
        ]);

        $connection->add('/ip/dhcp-server/network', [
            'address' => $plan['network'],
            'gateway' => $plan['gateway'],
            // The router answers DNS for its own HotSpot clients: that is what makes the
            // captive-portal redirect possible at all.
            'dns-server' => $plan['gateway'],
            'comment' => 'platform-hotspot',
        ]);

        return $plan['dhcp'];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    protected function dns(RouterOsConnection $connection, array $plan): string
    {
        $connection->execute('/ip/dns/set', [
            'servers' => $plan['dns_servers'],
            'allow-remote-requests' => 'yes',
        ]);

        return $plan['dns_servers'];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    protected function profile(RouterOsConnection $connection, array $plan): string
    {
        $attributes = [
            'hotspot-address' => $plan['gateway'],
            // The portal posts plain credentials once payment clears, so PAP has to be allowed.
            'login-by' => 'http-chap,http-pap',
            'html-directory' => 'hotspot',
            'use-radius' => 'no',
        ];

        // With a name, the HotSpot is served under it rather than a bare address, and the
        // router answers it in its own DNS so phones on the HotSpot resolve it.
        if ($plan['dns_name'] !== '') {
            $attributes['dns-name'] = $plan['dns_name'];
        }

        $existing = $connection->first('/ip/hotspot/profile', ['name' => $plan['profile']]);

        if ($existing === null) {
            $connection->add('/ip/hotspot/profile', ['name' => $plan['profile']] + $attributes);
        } else {
            $connection->update('/ip/hotspot/profile', $existing['.id'], $attributes);
        }

        return $plan['profile'];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    protected function server(RouterOsConnection $connection, array $plan): string
    {
        foreach ($connection->get('/ip/hotspot', ['interface' => $plan['interface']]) as $row) {
            if (($row['name'] ?? '') !== $plan['name']) {
                throw new InvalidArgumentException(__('HotSpot ":name" already runs on :interface. Remove it first, or choose another interface.', ['name' => $row['name'] ?? '?', 'interface' => $plan['interface']]));
            }
        }

        $connection->add('/ip/hotspot', [
            'name' => $plan['name'],
            'interface' => $plan['interface'],
            'address-pool' => $plan['pool_name'],
            'profile' => $plan['profile'],
            'addresses-per-mac' => '2',
            'disabled' => 'no',
        ]);

        return $plan['name'];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    protected function masquerade(RouterOsConnection $connection, array $plan): string
    {
        foreach ($connection->get('/ip/firewall/nat', ['comment' => 'platform-hotspot-masquerade']) as $row) {
            $connection->remove('/ip/firewall/nat', $row['.id']);
        }

        if (! $plan['masquerade']) {
            return __('Not added. Customers reach the internet only if something else masquerades them.');
        }

        $connection->add('/ip/firewall/nat', [
            'chain' => 'srcnat',
            'src-address' => $plan['network'],
            'action' => 'masquerade',
            'comment' => 'platform-hotspot-masquerade',
        ]);

        return $plan['network'];
    }

    /**
     * Let a phone that has paid nothing reach the page where it pays, and the providers that
     * take the money. Without this the redirect lands on a page that will not load, which a
     * customer experiences as broken wifi.
     *
     * @param  array<string, mixed>  $plan
     */
    protected function walledGarden(RouterOsConnection $connection, array $plan): string
    {
        $portalHost = (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: '');

        foreach (['platform-portal', 'platform-providers'] as $comment) {
            foreach ($connection->get('/ip/hotspot/walled-garden', ['comment' => $comment]) as $row) {
                $connection->remove('/ip/hotspot/walled-garden', $row['.id']);
            }
        }

        foreach (['platform-portal', 'platform-dns'] as $comment) {
            foreach ($connection->get('/ip/hotspot/walled-garden/ip', ['comment' => $comment]) as $row) {
                $connection->remove('/ip/hotspot/walled-garden/ip', $row['.id']);
            }
        }

        if ($portalHost !== '') {
            $connection->add('/ip/hotspot/walled-garden', ['dst-host' => $portalHost, 'action' => 'allow', 'comment' => 'platform-portal']);

            // The walled garden above belongs to the HotSpot's HTTP proxy and only ever covers
            // port 80. An https portal also has to be allowed by address.
            $address = filter_var($portalHost, FILTER_VALIDATE_IP) !== false ? $portalHost : gethostbyname($portalHost);

            if (filter_var($address, FILTER_VALIDATE_IP) !== false) {
                $connection->add('/ip/hotspot/walled-garden/ip', ['dst-address' => $address, 'action' => 'accept', 'comment' => 'platform-portal']);
            }
        }

        foreach (explode(',', $plan['dns_servers']) as $server) {
            $connection->add('/ip/hotspot/walled-garden/ip', ['dst-address' => $server, 'action' => 'accept', 'comment' => 'platform-dns']);
        }

        foreach (['*.safaricom.co.ke', '*.paystack.co'] as $host) {
            $connection->add('/ip/hotspot/walled-garden', ['dst-host' => $host, 'action' => 'allow', 'comment' => 'platform-providers']);
        }

        return $portalHost !== '' ? $portalHost : __('no portal address configured');
    }

    /**
     * Fasttrack sends established connections straight past the firewall — and past the
     * HotSpot with them. Left in place, customers stay online without a login and nobody's
     * usage is counted.
     */
    protected function noFasttrack(RouterOsConnection $connection): string
    {
        $rules = $connection->get('/ip/firewall/filter', ['action' => 'fasttrack-connection']);

        foreach ($rules as $row) {
            $connection->remove('/ip/firewall/filter', $row['.id']);
        }

        return trans_choice('{0} None were present|{1} Removed one rule|[2,*] Removed :count rules', count($rules), ['count' => count($rules)]);
    }

    /**
     * The pages the router serves, each carrying this router's portal key, so a customer is
     * handed to the right ISP's packages.
     *
     * Needs the ftp policy on the platform's API group, because RouterOS ties writing files to
     * it. Routers set up with this platform's script have it.
     */
    protected function pages(RouterOsConnection $connection, Router $router): string
    {
        $onFlash = $connection->first('/file', ['name' => 'flash']) !== null;
        $directories = $onFlash ? ['hotspot', 'flash/hotspot'] : ['hotspot'];
        $failed = [];

        foreach (array_keys(HotspotPageController::PAGES) as $page) {
            $url = route('portal.mikrotik', ['portalKey' => $router->portal_key, 'page' => $page]);

            foreach ($directories as $directory) {
                try {
                    $connection->execute('/tool/fetch', [
                        'url' => $url,
                        'mode' => str_starts_with($url, 'https://') ? 'https' : 'http',
                        'dst-path' => $directory.'/'.$page,
                        'check-certificate' => 'no',
                    ]);
                } catch (Throwable $e) {
                    $failed[] = $page.': '.$e->getMessage();
                }
            }
        }

        if ($failed !== []) {
            throw new \RuntimeException(implode('; ', array_unique($failed)));
        }

        return trans_choice(':count page installed|:count pages installed', count(HotspotPageController::PAGES), ['count' => count(HotspotPageController::PAGES)]);
    }

    /**
     * Remember what was built, so the setup script and this screen agree about the HotSpot
     * the next time either is opened.
     *
     * @param  array<string, mixed>  $plan
     */
    protected function remember(Router $router, array $plan): void
    {
        $bridged = $plan['interface'] === self::BRIDGE;

        // In the setup script's own terms — the customer port, the bridge it sits in, the
        // radio and its name — so a later wipe-and-rebuild script builds the same HotSpot.
        $router->setup_options = array_merge($router->setup_options ?? [], $bridged ? [
            'bridge_name' => self::BRIDGE,
            'with_wireless' => true,
            'ssid' => $plan['wifi_name'],
        ] : [], [
            'lan_interface' => $bridged ? $plan['port'] : $plan['interface'],
            'hotspot_name' => $plan['name'],
            'pool_name' => $plan['pool_name'],
            'hotspot_network' => $plan['network'],
            'hotspot_gateway' => $plan['gateway'],
            'hotspot_pool' => $plan['pool'],
            'hotspot_dns_servers' => $plan['dns_servers'],
            'hotspot_dns_name' => $plan['dns_name'],
            'with_hotspot' => true,
        ]);
        $router->save();
    }

    /**
     * @param  callable(): string  $run
     * @return array{step: string, status: string, detail: string}
     */
    protected function step(string $step, callable $run): array
    {
        try {
            return ['step' => $step, 'status' => self::STEP_OK, 'detail' => $run()];
        } catch (Throwable $e) {
            return ['step' => $step, 'status' => self::STEP_FAILED, 'detail' => $e->getMessage()];
        }
    }

    protected function name(string $value): string
    {
        $clean = (string) preg_replace('/[^A-Za-z0-9._-]/', '', $value);

        return $clean === '' ? 'billing-hotspot' : $clean;
    }
}
