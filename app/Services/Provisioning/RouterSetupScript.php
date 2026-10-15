<?php

namespace App\Services\Provisioning;

use App\Http\Controllers\HotspotPageController;
use App\Models\PortalSetting;
use App\Models\Router;
use App\Support\PublicUrl;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The one paste that turns a stock MikroTik into a router this platform can run.
 *
 * It exists because of a chicken and egg: everything else in this system reaches a router
 * over the API, and a router behind NAT has no API anyone can reach. So this is generated
 * here, carried by the operator, and pasted into the device's own terminal — the only channel
 * that always works.
 *
 * The order matters. The tunnel comes up first so the platform can see the device at all, the
 * API is bound to it and shut off everywhere else before it is enabled, and only then is the
 * HotSpot built. A script that opened the API to the internet first — even for a moment —
 * would be a router advertising an admin service to the world.
 *
 * Every command is written to be safe to run twice: an operator who pastes half of it, loses
 * their session and pastes the whole thing again must end up with one of each thing, not two.
 */
class RouterSetupScript
{
    public function __construct(protected TunnelAllocator $allocator) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function for(Router $router, array $options = []): string
    {
        $this->allocator->ensure($router);

        // What was chosen last time, under the platform defaults. An operator who named
        // wlan1 when adding the router must not come back to a script that bridges ether3.
        $defaults = $router->setupOptions();

        $wgInterface = $this->name($options['interface_name'] ?? $defaults['interface_name']);
        $bridge = $this->name($options['bridge_name'] ?? $defaults['bridge_name']);
        $hotspot = $this->name($options['hotspot_name'] ?? $defaults['hotspot_name']);
        $pool = $this->name($options['pool_name'] ?? $defaults['pool_name']);
        $lan = $this->name($options['lan_interface'] ?? $defaults['lan_interface']);
        $wan = $this->name($options['wan_interface'] ?? $defaults['wan_interface'] ?? 'ether1');
        $apiUser = $this->name($options['api_username'] ?? $defaults['api_username']);
        $apiGroup = $this->name($options['api_group'] ?? $defaults['api_group']);
        $withHotspot = ($options['with_hotspot'] ?? true) === true;

        // Wiping is the default: every router is built from nothing, so what is on it is
        // exactly what the platform knows about and nothing left over from before can get in
        // the way. Keeping an ISP's existing configuration is still possible, but it has to be
        // chosen.
        $wipe = ($options['wipe'] ?? $defaults['wipe'] ?? true) === true;

        $wireless = $this->name($options['wireless_interface'] ?? $defaults['wireless_interface'] ?? 'wlan1');
        $ssid = $this->ssid($options['ssid'] ?? $defaults['ssid'] ?? null, $router);
        $withWireless = ($options['with_wireless'] ?? $defaults['with_wireless'] ?? true) === true;

        $withPppoe = ($options['with_pppoe'] ?? $defaults['with_pppoe'] ?? false) === true;
        $pppoeInterface = $this->name($options['pppoe_interface'] ?? $defaults['pppoe_interface'] ?? 'ether4');
        $pppoeService = $this->name($options['pppoe_service_name'] ?? $defaults['pppoe_service_name'] ?? 'isp');
        $pppoeNetwork = (string) ($options['pppoe_network'] ?? $defaults['pppoe_network'] ?? '10.20.0.0/24');
        $pppoeGateway = (string) ($options['pppoe_gateway'] ?? $defaults['pppoe_gateway'] ?? '10.20.0.1');

        // How the router's own internet arrives. Only a wipe uses it — an overlay leaves the
        // uplink the router already has — but it is remembered either way.
        $wanMode = (string) ($options['wan_mode'] ?? $defaults['wan_mode'] ?? 'dhcp');
        $wanAddress = (string) ($options['wan_address'] ?? $defaults['wan_address'] ?? '');
        $wanGateway = (string) ($options['wan_gateway'] ?? $defaults['wan_gateway'] ?? '');
        $wanDns = (string) ($options['wan_dns'] ?? $defaults['wan_dns'] ?? '');
        $wanPppoeUser = (string) ($options['wan_pppoe_user'] ?? $defaults['wan_pppoe_user'] ?? '');

        // Kept off setup_options: it is the ISP's own provider's credential, and that column
        // is plain JSON. Only replaced when one is given, so re-rendering the page does not
        // wipe a password nobody retyped.
        if (filled($options['wan_pppoe_password'] ?? null)) {
            $router->wan_pppoe_password = (string) $options['wan_pppoe_password'];
        }

        $network = (string) ($options['hotspot_network'] ?? $defaults['hotspot_network']);
        $gateway = (string) ($options['hotspot_gateway'] ?? $defaults['hotspot_gateway']);
        [$poolStart, $poolEnd] = $this->poolRange($network, $gateway);

        $serverAddress = (string) config('wireguard.server_address');
        $subnet = (string) config('wireguard.subnet');
        $localSubnet = $this->subnet($options['local_api_subnet'] ?? config('wireguard.local_api_subnet'));
        $portalHost = (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');

        // The API password the router already has, unless a new one is asked for. It used to
        // be replaced on every call, and the router form calls this on every save — so editing
        // a router's name after pasting its script left the device holding one password and
        // the platform another, and the tunnel came up to a router that refused to talk.
        // Rotation is now deliberate: the Rotate keys button asks for it.
        $apiPassword = $options['api_password'] ?? (
            filled($router->api_password) && ($options['rotate_api_password'] ?? false) !== true
                ? (string) $router->api_password
                : Str::password(24, symbols: false)
        );

        // Remembered so the next visit to this page produces the same script rather than the
        // defaults, and so an operator can regenerate one without re-answering.
        $router->setup_options = [
            'lan_interface' => $lan,
            'wan_interface' => $wan,
            'with_hotspot' => $withHotspot,
            'wipe' => $wipe,
            'interface_name' => $wgInterface,
            'bridge_name' => $bridge,
            'hotspot_name' => $hotspot,
            'pool_name' => $pool,
            'hotspot_network' => $network,
            'hotspot_gateway' => $gateway,
            'api_username' => $apiUser,
            'api_group' => $apiGroup,
            'wireless_interface' => $wireless,
            'ssid' => $ssid,
            'with_wireless' => $withWireless,
            'with_pppoe' => $withPppoe,
            'pppoe_interface' => $pppoeInterface,
            'pppoe_service_name' => $pppoeService,
            'pppoe_network' => $pppoeNetwork,
            'pppoe_gateway' => $pppoeGateway,
            'wan_mode' => $wanMode,
            'wan_address' => $wanAddress,
            'wan_gateway' => $wanGateway,
            'wan_dns' => $wanDns,
            'wan_pppoe_user' => $wanPppoeUser,
        ];

        // A reset puts `admin` back on the factory password — blank on older boards — so the
        // rebuild disables it and hands the ISP an administrator of their own instead. Issued
        // once and kept: the page regenerates the script on every render, and a password that
        // changed each time would never match the one the router was actually built with.
        if ($wipe) {
            $router->admin_username ??= 'isp-admin';
            $router->admin_password ??= Str::password(20, symbols: false);
        }

        $router->api_username = $apiUser;
        $router->api_password = $apiPassword;
        // From here on the platform talks to this device down the tunnel, not over the
        // internet. This is the line that makes the whole arrangement work — where there is a
        // tunnel to talk down.
        //
        // On a machine with no WireGuard server there is not, and 10.8.0.x is an address to
        // nowhere. Worse, this runs on every render of the setup page rather than on a button:
        // a developer who merely *looked* at the script found their bench router had stopped
        // answering, with an error naming a host they never typed. Leaving the address alone is
        // what keeps "proven locally first" true.
        if (config('wireguard.manage_over_tunnel') === true) {
            $router->host = (string) $router->wg_address;
            $router->api_port = 8728;
            $router->uses_tls = false;
        }

        $router->save();

        // Wiping reboots the device, which throws away whatever is still being pasted. So the
        // operator pastes a short bootstrap instead: it downloads the real configuration to
        // the router, then resets with run-after-reset pointing at it. What comes back is a
        // device configured from nothing.
        if ($wipe) {
            // Checked before a link is issued rather than when the router fetches its file:
            // an incomplete static or PPPoE uplink is a router that comes back unreachable,
            // and the operator should hear about it while they can still correct it.
            $this->uplink($router);

            return $this->bootstrap($router);
        }

        $lines = [];

        $lines[] = $this->banner($router, $portalHost);
        $lines[] = $this->tunnelSection($router, $wgInterface, $subnet);
        $lines[] = $this->apiSection($router, $wgInterface, $apiUser, $apiGroup, $apiPassword, $serverAddress, $subnet, $localSubnet);

        if ($withHotspot) {
            $lines[] = $this->hotspotSection($router, $bridge, $lan, $pool, $hotspot, $network, $gateway, $poolStart, $poolEnd, $portalHost);

            // After the bridge exists, or there is nothing to put the radio into.
            if ($withWireless) {
                $lines[] = $this->wirelessSection($wireless, $ssid, $bridge);
            }
        }

        if ($withPppoe) {
            $lines[] = $this->pppoeSection($pppoeInterface, $pppoeService, $pppoeNetwork, $pppoeGateway, $hotspot.'-pppoe-pool');
        }

        $lines[] = $this->closingSection($router);

        return implode("\n", $lines)."\n";
    }

    /**
     * The file a wiped router runs on its first boot.
     *
     * Everything the overlay script does, plus the things a reset takes away and nothing else
     * would put back: the uplink, NAT, and a way in for the operator. A device that comes back
     * from a reset with no route to the internet can never reach us, and is then only
     * recoverable by walking to it.
     */
    public function configurationFile(Router $router): string
    {
        $options = $router->setupOptions();

        // A router whose wipe script predates the administrator account would otherwise be
        // rebuilt with a full-rights user and an empty password — the very hole this file
        // exists to close.
        if (blank($router->admin_password)) {
            $router->admin_username ??= 'isp-admin';
            $router->admin_password = Str::password(20, symbols: false);
            $router->save();
        }

        $wgInterface = $this->name($options['interface_name']);
        $bridge = $this->name($options['bridge_name']);
        $hotspot = $this->name($options['hotspot_name']);
        $pool = $this->name($options['pool_name']);
        $lan = $this->name($options['lan_interface']);
        $apiUser = $this->name($options['api_username']);
        $apiGroup = $this->name($options['api_group']);
        $withHotspot = ($options['with_hotspot'] ?? true) === true;

        $network = (string) $options['hotspot_network'];
        $gateway = (string) $options['hotspot_gateway'];
        [$poolStart, $poolEnd] = $this->poolRange($network, $gateway);

        $serverAddress = (string) config('wireguard.server_address');
        $subnet = (string) config('wireguard.subnet');
        $portalHost = (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');

        // After the preamble — which builds the way in and must run in the open — each section
        // runs on its own. RouterOS stops a run-after-reset file at its first error, and a
        // first boot once ended at one firewall rule in the tunnel section: no API user, no
        // HotSpot, no wifi, and a router the platform could see and not speak to. Now a
        // failing section is logged under its own name and the rest still runs.
        $lines = [
            $this->resetPreamble($router, $options),
            $this->deferred($this->tunnelSection($router, $wgInterface, $subnet), 'the management tunnel'),
            $this->deferred($this->apiSection($router, $wgInterface, $apiUser, $apiGroup, (string) $router->api_password, $serverAddress, $subnet, $this->subnet(config('wireguard.local_api_subnet'))), 'the platform API user'),
        ];

        if ($withHotspot) {
            // Two blocks rather than one: the login-page downloads are half the section, and
            // kept together they would push it past the length RouterOS allows a string. Split
            // there, a page that fails to download also never costs the HotSpot itself.
            $section = $this->hotspotSection($router, $bridge, $lan, $pool, $hotspot, $network, $gateway, $poolStart, $poolEnd, $portalHost);
            $split = strpos($section, '# 3b. The login page');
            $lines[] = $this->deferred($split === false ? $section : substr($section, 0, $split), 'the HotSpot');

            if ($split !== false) {
                $lines[] = $this->deferred(substr($section, $split), 'the HotSpot login pages');
            }

            if (($options['with_wireless'] ?? true) === true) {
                $lines[] = $this->wirelessSection(
                    $this->name($options['wireless_interface'] ?? 'wlan1'),
                    $this->ssid($options['ssid'] ?? null, $router),
                    $bridge,
                );
            }
        }

        if (($options['with_pppoe'] ?? false) === true) {
            $lines[] = $this->deferred($this->pppoeSection(
                $this->name($options['pppoe_interface'] ?? 'ether4'),
                $this->name($options['pppoe_service_name'] ?? 'isp'),
                (string) ($options['pppoe_network'] ?? '10.20.0.0/24'),
                (string) ($options['pppoe_gateway'] ?? '10.20.0.1'),
                $hotspot.'-pppoe-pool',
            ), 'the PPPoE server');
        }

        $lines[] = $this->closingSection($router);

        // Nobody is watching a run-after-reset script: it runs before anyone can log in, and
        // :put goes nowhere. The log is the only account of how far it got.
        $lines[] = ':log info "platform-setup: complete"';

        return implode("\n", $lines)."\n";
    }

    /**
     * How a wiped router reaches the internet, validated.
     *
     * Everything here is written into the router's configuration, and a mistake in any of it
     * is a router that comes back from its reset with no way out — recoverable only by
     * walking to it. So anything that is not plainly right is refused rather than sanitised
     * into something that merely looks right.
     *
     * @return array{mode: string, interface: string, address: string, gateway: string, dns: string, user: string, password: string}
     *
     * @throws InvalidArgumentException
     */
    public function uplink(Router $router): array
    {
        $options = $router->setupOptions();
        $mode = (string) ($options['wan_mode'] ?? 'dhcp');
        $wan = $this->name($options['wan_interface'] ?? 'ether1');

        $uplink = [
            'mode' => $mode,
            // A PPPoE uplink is its own interface on top of the port; everything that names
            // "the way out" — NAT, the WAN list — has to name that, not the port.
            'interface' => $mode === 'pppoe' ? 'pppoe-uplink' : $wan,
            'address' => '',
            'gateway' => '',
            'dns' => '',
            'user' => '',
            'password' => '',
        ];

        if ($mode === 'dhcp') {
            return $uplink;
        }

        if ($mode === 'static') {
            $address = $this->subnet($options['wan_address'] ?? '');
            $gateway = $this->subnet($options['wan_gateway'] ?? '');

            if (! str_contains($address, '/') || $gateway === '' || str_contains($gateway, '/')) {
                throw new InvalidArgumentException(__('A static uplink needs an address with its prefix (e.g. 196.201.1.10/29) and a gateway address.'));
            }

            $dns = [];

            foreach (explode(',', (string) ($options['wan_dns'] ?? '')) as $server) {
                $server = $this->subnet($server);

                if ($server !== '' && ! str_contains($server, '/')) {
                    $dns[] = $server;
                }
            }

            return array_merge($uplink, [
                'address' => $address,
                'gateway' => $gateway,
                'dns' => implode(',', $dns ?: ['1.1.1.1', '8.8.8.8']),
            ]);
        }

        if ($mode === 'pppoe') {
            $user = (string) preg_replace('/[^A-Za-z0-9@._\-]/', '', (string) ($options['wan_pppoe_user'] ?? ''));
            $password = (string) $router->wan_pppoe_password;

            if ($user === '' || $password === '') {
                throw new InvalidArgumentException(__('A PPPoE uplink needs the username and password your provider gave you.'));
            }

            return array_merge($uplink, ['user' => $user, 'password' => $this->quoted($password)]);
        }

        throw new InvalidArgumentException(__('Choose how this router gets its internet: DHCP, a static address or PPPoE.'));
    }

    /**
     * The short paste that wipes a router and rebuilds it from nothing.
     *
     * Three commands and a wait. It cannot be longer than this: `reset-configuration` reboots
     * the device immediately, so anything after it in the paste is never read. The real
     * configuration therefore has to be a file on the router *before* the reset, which is what
     * the fetch is for.
     */
    protected function bootstrap(Router $router): string
    {
        $token = $this->issueProvisionToken($router);
        $url = route('routers.provision-script', ['token' => $token]);
        $minutes = (int) config('wireguard.provision_link_minutes');

        // RouterOS wants the scheme named separately from the URL, and rejects the fetch when
        // the two disagree. Read from the URL rather than assumed, so a platform running on
        // plain http during a local trial still works.
        $mode = str_starts_with($url, 'https://') ? 'https' : 'http';
        $options = $router->setupOptions();
        $wan = $this->name($options['wan_interface'] ?? 'ether1');
        $lan = $this->name($options['lan_interface']);
        $uplink = match ((string) ($options['wan_mode'] ?? 'dhcp')) {
            'static' => 'a static address',
            'pppoe' => 'a PPPoE login',
            default => 'DHCP',
        };
        $admin = $this->name($router->admin_username ?? 'isp-admin');
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: 'localhost');

        return <<<SCRIPT
        # =====================================================================
        #  {$router->name} — WIPE AND REBUILD
        #
        #  This ERASES everything on the router and builds it again from
        #  nothing. There is no undo and no backup kept.
        #
        #  Before you paste it, be certain of two things:
        #
        #    * {$wan} is the port the router's internet comes in on, and it
        #      gets that internet by {$uplink}.
        #    * {$lan} is the port your customers connect to.
        #
        #  If those are the wrong way round the router will come back with no
        #  way to reach the internet, and the only fix is to walk to it with a
        #  cable. Check them in /interface print first.
        #
        #  Afterwards the stock "admin" account is disabled. Log in as
        #  "{$admin}" with the password on this router's setup page. Winbox
        #  answers by MAC on any port, and by IP on any spare port, which
        #  gives a plugged-in laptop an address (192.168.88.x by default).
        #  Never from the internet.
        #
        #  The link below works once and expires in {$minutes} minutes.
        # =====================================================================

        # Everything below is one block, deliberately. Pasted line by line, a
        # check that failed would print its message and RouterOS would carry on
        # to the next line anyway — all the way to the reset. As one block, the
        # first failure stops the lot and nothing on the router has changed.
        {
            # 0. Can this router reach the platform at all? Checked first, so a
            #    missing cable is a clear sentence now rather than a wiped router
            #    that cannot finish later.
            :put "Checking {$wan} and the internet…"
            :if ([:len [/interface find name="{$wan}"]] = 0) do={
                :error "There is no port called {$wan} on this router. Fix the uplink on the setup page and copy the script again. Nothing has been changed."
            }
            :if ([/interface get [find name="{$wan}"] running] = false) do={
                :error "{$wan} has no cable link. Plug your internet (modem or upstream router) into {$wan} and paste again. Nothing has been changed."
            }
            :do { :resolve "{$host}" } on-error={
                :error "This router cannot look up {$host}: it has no working internet or DNS on {$wan}. Check that {$wan} gets internet, then paste again. Nothing has been changed."
            }

            # 1. Fetch the configuration onto the router. It has to be on the
            #    device before the reset, because the reset reboots immediately.
            #    Boards with a flash/ folder keep only what is in it across a
            #    reboot, so it goes there when present.
            :put "Downloading this router's configuration…"
            :local file "platform-setup.rsc"
            :if ([:len [/file find name="flash"]] > 0) do={ :set file "flash/platform-setup.rsc" }
            :do {
                /tool/fetch url="{$url}" mode={$mode} dst-path=\$file check-certificate=no
            } on-error={
                :error "The download failed. The setup link may be used up or expired — reload the setup page and copy the script again. Nothing has been changed."
            }
            :delay 1s
            :if ([:len [/file find name=\$file]] = 0) do={
                :error "The configuration did not arrive. Reload the setup page and copy the script again. Nothing has been changed."
            }

            # 2. Wipe at once, reboot, and run what was downloaded on the way
            #    back up. Run as a stored script because typed at the terminal
            #    the reset stops to ask "Reset anyway? [y/N]". no-defaults: the
            #    downloaded file is the entire configuration.
            /system/script remove [find name="platform-reset"]
            /system/script add name="platform-reset" source=("/system/reset-configuration no-defaults=yes skip-backup=yes run-after-reset=" . \$file)
            :put "All checks passed. Resetting now — the router reboots and comes back configured in about two minutes. Watch the setup page: it follows along."
            /system/script run platform-reset
        }
        SCRIPT;
    }

    /**
     * What a wiped router needs before anything else works — and, first of all, a way in.
     *
     * A reset takes away the uplink, NAT, the firewall and every safe way in, and puts `admin`
     * back on its factory password — blank on older boards. Nothing else in this script would
     * put any of that right, and a router that cannot reach the internet cannot reach us.
     *
     * **Access comes before everything that can fail.** Nobody is watching this run: it
     * executes on the way up from a reboot. If it stops halfway, whatever it had not reached
     * yet stays missing — and a router that stopped before its way in is one nobody can open,
     * not even in Winbox. So the first thing it does is make the device reachable on a cable:
     * the ISP's administrator, MAC Winbox on every port, and a technician port that hands a
     * laptop an address. Only then the uplink, the tunnel and the HotSpot, any of which may
     * fail on a given board without costing anyone the router.
     *
     * It waits for conditions rather than guessed delays, and logs its progress — the router's
     * log is the only place anyone can later see how far it got.
     *
     * @param  array<string, mixed>  $options
     */
    protected function resetPreamble(Router $router, array $options): string
    {
        $identity = $this->name($router->slug);
        $wan = $this->name($options['wan_interface'] ?? 'ether1');
        $lan = $this->name($options['lan_interface'] ?? 'ether3');
        $pppoeInterface = ($options['with_pppoe'] ?? false) === true ? $this->name($options['pppoe_interface'] ?? 'ether4') : '';
        $uplink = $this->uplink($router);
        $out = $uplink['interface'];
        $endpoint = $this->name(config('wireguard.endpoint'));
        $admin = $this->name($router->admin_username ?? 'isp-admin');
        $adminPassword = $this->quoted((string) $router->admin_password);
        [$techAddress, $techNetwork, $techPool] = $this->technicianNetwork($options);

        $wayOut = $this->deferred(match ($uplink['mode']) {
            'static' => "/ip/address add address={$uplink['address']} interface=\"{$wan}\" comment=\"Uplink\"\n"
                ."/ip/route add dst-address=0.0.0.0/0 gateway={$uplink['gateway']} comment=\"Uplink\"\n"
                ."/ip/dns set servers={$uplink['dns']}",
            'pppoe' => "/interface/pppoe-client add name=\"{$out}\" interface=\"{$wan}\" user=\"{$uplink['user']}\" password=\"{$uplink['password']}\" add-default-route=yes use-peer-dns=yes disabled=no comment=\"Uplink\"\n"
                ."/interface/list/member add list=WAN interface=\"{$out}\" comment=\"platform-base\"",
            default => "/ip/dhcp-client add interface=\"{$wan}\" disabled=no add-default-route=yes use-peer-dns=yes comment=\"Uplink\"",
        }, 'the uplink on '.$wan);

        $ntp = $this->deferred("/system/ntp/client set enabled=yes\n/system/ntp/client/servers add address=pool.ntp.org", 'the NTP client');

        // The address ranges customers are handed. Winbox and SSH are closed to them by
        // address: a customer who has paid for an hour of wifi has no business being offered a
        // login. The technician port is not one of them.
        $customerRules = [];

        if (($options['with_hotspot'] ?? true) === true && ($network = $this->subnet($options['hotspot_network'] ?? '')) !== '') {
            $customerRules[] = "add chain=input protocol=tcp dst-port=8291,22 src-address={$network} action=drop comment=\"platform-base: customers do not administer the router\"";
        }

        if (($options['with_pppoe'] ?? false) === true && ($network = $this->subnet($options['pppoe_network'] ?? '')) !== '') {
            $customerRules[] = "add chain=input protocol=tcp dst-port=8291,22 src-address={$network} action=drop comment=\"platform-base: customers do not administer the router\"";
        }

        $customerRules = implode("\n", $customerRules);

        return <<<SCRIPT
        # =====================================================================
        #  {$router->name}
        #  Built from nothing after a reset. Do not edit — regenerate instead.
        #
        #  If this router is ever unreachable, plug a laptop into any spare
        #  port: it is given an address and Winbox answers at {$techAddress}.
        # =====================================================================

        :log info "platform-setup: starting"
        /system/identity set name="{$identity}"

        # Interfaces are still appearing while a freshly reset router boots.
        # Wait for the two this configuration names, but never stop over them:
        # a missing port costs that part of the setup, not the way in.
        :local waited 0
        :while ((([:len [/interface find name="{$wan}"]] = 0) || ([:len [/interface find name="{$lan}"]] = 0)) && (\$waited < 60)) do={
            :delay 1s
            :set waited (\$waited + 1)
        }
        :if ([:len [/interface find name="{$wan}"]] = 0) do={
            :log error "platform-setup: {$wan} never appeared. This board has no such port; regenerate the script with the right uplink. Carrying on."
        }

        # ---------------------------------------------------------------------
        # 0. A way in, before anything else
        #
        # Everything after this can fail on some board without leaving the
        # router locked. This cannot be allowed to.
        # ---------------------------------------------------------------------

        # The ISP's own administrator. The stock admin is back on its factory
        # password after a reset — blank on older boards — so it is disabled,
        # but only once this account demonstrably exists.
        /user add name="{$admin}" group=full password="{$adminPassword}" comment="ISP administrator"
        :if ([:len [/user find name="{$admin}"]] > 0) do={
            /user disable [find name="admin"]
        }

        # Winbox over MAC works with no IP address at all, so it is the way in
        # that survives every other mistake. Left on every port.
        /tool/mac-server set allowed-interface-list=all
        /tool/mac-server/mac-winbox set allowed-interface-list=all
        /ip/neighbor/discovery-settings set discover-interface-list=all

        # Technician port: every ethernet port not given another job is bridged
        # and hands a laptop an address, so Winbox also works by IP at
        # {$techAddress}. The same idea as MikroTik's own default setup.
        /interface/bridge add name="bridge-tech" comment="Technician access: plug a laptop in here"
        :foreach e in=[/interface/ethernet find] do={
            :local n [/interface/ethernet get \$e name]
            :if ((\$n != "{$wan}") && (\$n != "{$lan}") && (\$n != "{$pppoeInterface}")) do={
                /interface/bridge/port add bridge="bridge-tech" interface=\$n
            }
        }
        /ip/address add address={$techAddress} interface="bridge-tech" comment="Technician access"
        /ip/pool add name="tech-pool" ranges={$techPool}
        /ip/dhcp-server add name="dhcp-tech" interface="bridge-tech" address-pool="tech-pool" lease-time=1h disabled=no
        /ip/dhcp-server/network add address={$techNetwork} gateway={$this->hostOf($techAddress)} dns-server={$this->hostOf($techAddress)} comment="Technician access"
        :log info "platform-setup: technician access ready at {$techAddress}"

        # ---------------------------------------------------------------------
        # 0b. Which side is which, and keeping the router itself safe
        #
        # WAN is the way out; LAN is every other interface — the technician
        # port, the HotSpot, the tunnel — so anything added later lands on the
        # safe side. Input from the internet is dropped; Winbox and SSH stay
        # open from the LAN, which is where a technician stands.
        # ---------------------------------------------------------------------

        /interface/list
        add name=WAN comment="platform-base"
        add name=LAN include=all exclude=WAN comment="platform-base"

        /interface/list/member
        add list=WAN interface="{$wan}" comment="platform-base"

        /ip/firewall/filter
        add chain=input connection-state=established,related action=accept comment="platform-base"
        add chain=input connection-state=invalid action=drop comment="platform-base"
        add chain=input protocol=icmp action=accept comment="platform-base"
        {$customerRules}
        add chain=input in-interface-list=!LAN action=drop comment="platform-base: nothing from the internet"
        add chain=forward connection-state=established,related action=accept comment="platform-base"
        add chain=forward connection-state=invalid action=drop comment="platform-base"
        add chain=forward connection-state=new connection-nat-state=!dstnat in-interface-list=WAN action=drop \\
            comment="platform-base: nothing from the internet that was not forwarded on purpose"

        /ip/service
        set telnet disabled=yes
        set ftp disabled=yes
        set www disabled=yes
        set www-ssl disabled=yes
        set api-ssl disabled=yes

        /tool/bandwidth-server set enabled=no

        # ---------------------------------------------------------------------
        # 0c. The way out ({$uplink['mode']})
        # ---------------------------------------------------------------------

        :log info "platform-setup: bringing up the uplink on {$wan}"
        {$wayOut}

        /ip/firewall/nat
        add chain=srcnat out-interface-list=WAN action=masquerade comment="Uplink"

        # ---------------------------------------------------------------------
        # 0d. Waiting for the internet
        #
        # Everything after this downloads something or dials the platform. On a
        # slow uplink a fixed pause runs out first.
        # ---------------------------------------------------------------------

        :log info "platform-setup: waiting for a default route"
        :set waited 0
        :while (([:len [/ip/route find where active and dst-address="0.0.0.0/0"]] = 0) && (\$waited < 120)) do={
            :delay 1s
            :set waited (\$waited + 1)
        }
        :if ([:len [/ip/route find where active and dst-address="0.0.0.0/0"]] = 0) do={
            :log error "platform-setup: no route to the internet after 2 minutes. Check the uplink on {$wan}; carrying on so the rest is in place when it comes up."
        }

        :local resolved false
        :set waited 0
        :while ((!\$resolved) && (\$waited < 30)) do={
            :do {
                :resolve "{$endpoint}"
                :set resolved true
            } on-error={
                :delay 2s
                :set waited (\$waited + 1)
            }
        }
        :if (!\$resolved) do={
            :log error "platform-setup: cannot resolve {$endpoint}. The tunnel will keep retrying once DNS works."
        }

        # The board has no clock battery on most models. Without this every log
        # line after a power cut reads 1970.
        {$ntp}

        :log info "platform-setup: uplink ready, building the rest"
        SCRIPT;
    }

    /**
     * The technician port's network: MikroTik's own 192.168.88.0/24 where it is free, so a
     * technician finds the router where they would look for any MikroTik — unless the HotSpot
     * or PPPoE already uses it, in which case the next one along.
     *
     * @param  array<string, mixed>  $options
     * @return array{string, string, string} address with prefix, network, pool range
     */
    protected function technicianNetwork(array $options): array
    {
        $taken = array_filter([
            ($options['with_hotspot'] ?? true) === true ? (string) ($options['hotspot_network'] ?? '') : '',
            ($options['with_pppoe'] ?? false) === true ? (string) ($options['pppoe_network'] ?? '') : '',
        ]);

        foreach (['192.168.88', '192.168.77', '172.31.255'] as $prefix) {
            $clash = false;

            foreach ($taken as $network) {
                if (str_starts_with($network, $prefix.'.')) {
                    $clash = true;
                }
            }

            if (! $clash) {
                return ["{$prefix}.1/24", "{$prefix}.0/24", "{$prefix}.10-{$prefix}.254"];
            }
        }

        return ['172.31.254.1/24', '172.31.254.0/24', '172.31.254.10-172.31.254.254'];
    }

    protected function hostOf(string $cidr): string
    {
        return explode('/', $cidr)[0];
    }

    /**
     * Commands that might not exist on this board, run so that a missing menu skips them
     * instead of killing the whole file.
     *
     * RouterOS reads a run-after-reset script in full before running any of it, and a command
     * it does not recognise — `/interface/wifi` on a board without the wifi package, or
     * `/interface/wireless` on one without the legacy one — is an error *in the file*, not in
     * the line. One such command, anywhere, and the router comes back from its reset with no
     * configuration at all and its radio switched off: visible to nobody, not even Winbox.
     * Handed to `:parse` as a string, the commands are only read when they run, and a failure
     * is a line in the log.
     */
    protected function deferred(string $commands, string $what): string
    {
        // Comments and blank lines are dropped: they explain the readable sections, mean
        // nothing inside a string handed to :parse, and older RouterOS caps a string near
        // 4 KB. Kept in, the HotSpot section alone was twice that.
        $commands = implode("\n", array_filter(
            explode("\n", str_replace("\r\n", "\n", $commands)),
            fn (string $line): bool => trim($line) !== '' && ! str_starts_with(ltrim($line), '#'),
        ));

        $escaped = str_replace(['\\', '"', '$', "\n"], ['\\\\', '\\"', '\\$', '\\n'], $commands);

        return ':do { [[:parse "'.$escaped.'"]] } on-error={ :log warning "platform-setup: '.$what.' skipped or failed" }';
    }

    /**
     * A value safe inside a RouterOS double-quoted string.
     *
     * Backslash, quote and dollar are the three characters that mean something there; control
     * characters have no business in a password and would break the line they sit on.
     */
    protected function quoted(string $value): string
    {
        $value = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $value);

        return str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value);
    }

    /**
     * The single-use link this router fetches its configuration from.
     *
     * **Reused while it is still good.** Minting a fresh one on every call looks harmless and
     * is not: the setup screen regenerates the script on every render, so an operator who
     * copied a script, walked to the router and pasted it found the link already dead —
     * killed by their own page re-rendering behind them. Observed in production, as a router
     * asking for a token the database had replaced eight minutes earlier.
     *
     * A new one is issued only when there is none, when the last has expired, or when it has
     * already been spent. Rotating the router's keys clears it deliberately.
     */
    protected function issueProvisionToken(Router $router): string
    {
        // Read fresh rather than trusting the instance in hand: the router is spent by a
        // different request — the device's own download — so an in-memory copy made before
        // that would happily hand out a token the router has already used.
        $router->refresh();

        if ($router->provisionTokenIsUsable()) {
            return (string) $router->provision_token;
        }

        $token = Str::random(48);

        $router->provision_token = $token;
        $router->provision_token_expires_at = now()->addMinutes((int) config('wireguard.provision_link_minutes'));
        $router->provision_fetched_at = null;
        // Saved here rather than by the caller: a token written into a script but not into the
        // database is a link that 404s the moment the router tries it.
        $router->save();

        return $token;
    }

    /**
     * The wifi customers connect to.
     *
     * Without this a wiped router has a radio that is switched off and nameless, and a HotSpot
     * nobody can find — there is simply nothing on the phone's list to tap.
     *
     * The network is left **open** on purpose. The HotSpot is what authenticates people, and a
     * wifi password would turn a customer away before they had the chance to buy anything.
     *
     * Two radio stacks exist and the commands are not interchangeable: older boards use
     * /interface/wireless, newer ones the wifi package. The script asks the device which it has
     * rather than the platform guessing from a model name it may never have seen.
     */
    protected function wirelessSection(string $wireless, string $ssid, string $bridge): string
    {
        // One block per radio stack. A board has one or the other, and the one it lacks is a
        // menu it does not recognise — which is why each is deferred rather than written out:
        // see deferred(). The SSID is already held to characters that cannot close a string.
        $legacy = $this->deferred(
            ":if ([:len [/interface/wireless find]] > 0) do={\n"
            ."  :if ([:len [/interface/wireless/security-profiles find name=\"platform-open\"]] = 0) do={ /interface/wireless/security-profiles add name=\"platform-open\" mode=none }\n"
            ."  /interface/wireless set [find default-name=\"{$wireless}\"] ssid=\"{$ssid}\" mode=ap-bridge band=2ghz-b/g/n channel-width=20/40mhz-XX frequency=auto security-profile=\"platform-open\" disabled=no\n"
            ."  :delay 3s\n"
            ."  /interface/bridge/port remove [find interface=\"{$wireless}\"]\n"
            ."  /interface/bridge/port add bridge=\"{$bridge}\" interface=\"{$wireless}\"\n"
            ."  :log info \"platform-setup: wifi {$ssid} on {$wireless}\"\n"
            .'}',
            'the wifi (wireless package)',
        );

        $wifi = $this->deferred(
            ":if ([:len [/interface/wifi find]] > 0) do={\n"
            ."  /interface/wifi set [find] configuration.ssid=\"{$ssid}\" configuration.mode=ap security.authentication-types=\"\" disabled=no\n"
            ."  :delay 3s\n"
            ."  :foreach w in=[/interface/wifi find] do={\n"
            ."    :local n [/interface/wifi get \$w name]\n"
            ."    /interface/bridge/port remove [find interface=\$n]\n"
            ."    /interface/bridge/port add bridge=\"{$bridge}\" interface=\$n\n"
            ."  }\n"
            ."  :log info \"platform-setup: wifi {$ssid} on the wifi package\"\n"
            .'}',
            'the wifi (wifi package)',
        );

        return <<<SCRIPT

        # ---------------------------------------------------------------------
        # 3c. The wifi itself
        #
        # Open by design: the HotSpot is the thing that checks who someone is,
        # and a wifi password would stop a customer before they could pay. The
        # radio goes into the HotSpot bridge, or the SSID appears and hands out
        # nothing.
        # ---------------------------------------------------------------------

        :put "Bringing up the wifi…";
        {$legacy}
        {$wifi}
        SCRIPT;
    }

    /**
     * A PPPoE server, so the secrets this platform writes can authenticate somebody.
     *
     * Without one, `/ppp/secret` is a list of credentials no device will ever be asked for.
     *
     * Its own interface by default rather than the HotSpot bridge: both would answer the same
     * client, and a subscriber on a fixed line would find themselves at a captive portal.
     */
    protected function pppoeSection(string $interface, string $service, string $network, string $gateway, string $pool): string
    {
        $prefix = implode('.', array_slice(explode('.', explode('/', $network)[0]), 0, 3));

        return <<<SCRIPT

        # ---------------------------------------------------------------------
        # 3d. PPPoE
        #
        # For subscribers on a fixed line. The platform writes their credentials
        # to /ppp/secret; this is the thing that asks for them.
        # ---------------------------------------------------------------------

        :put "Setting up PPPoE…";

        /interface/pppoe-server/server remove [find service-name="{$service}"]
        /ppp/profile remove [find name="platform-pppoe"]
        /ip/pool remove [find name="{$pool}"]

        /ip/pool
        add name="{$pool}" ranges={$prefix}.10-{$prefix}.254

        /ppp/profile
        add name="platform-pppoe" \\
            local-address={$gateway} \\
            remote-address="{$pool}" \\
            dns-server=1.1.1.1,8.8.8.8 \\
            only-one=yes \\
            change-tcp-mss=yes

        /interface/pppoe-server/server
        add service-name="{$service}" \\
            interface="{$interface}" \\
            default-profile="platform-pppoe" \\
            authentication=pap,chap,mschap1,mschap2 \\
            one-session-per-host=yes \\
            disabled=no

        # PPPoE clients are routed rather than bridged, so their subnet needs a
        # way out of its own.
        /ip/firewall/nat
        remove [find comment="platform-pppoe"]
        add chain=srcnat src-address={$network} action=masquerade comment="platform-pppoe"
        SCRIPT;
    }

    protected function banner(Router $router, string $portalHost): string
    {
        // A script generated from a laptop points the router at that laptop. It is the right
        // thing while testing on the same wifi and useless in a shop, and the difference is
        // invisible once the script has been copied somewhere else — so it says so in itself.
        $warning = PublicUrl::isReachableByCustomers((string) config('app.url'))
            ? ''
            : "\n#  ⚠ WARNING: {$portalHost} is only reachable from one network. Customers\n".
              "#    on this router will not be able to open the payment page. Generate\n".
              "#    this script from the live platform instead.\n#";

        return <<<SCRIPT
        # =====================================================================
        #  {$router->name}
        #  Setup for {$portalHost}
        #{$warning}
        #  Paste this whole block into the router's terminal (Winbox > New
        #  Terminal, or SSH). It is safe to run more than once.
        #
        #  This script contains the keys and the password for this router
        #  only. Treat it like a password: do not forward it, and generate a
        #  new one if it goes astray.
        # =====================================================================
        SCRIPT;
    }

    /**
     * The tunnel. Everything else depends on this being up.
     */
    protected function tunnelSection(Router $router, string $interface, string $subnet): string
    {
        $endpoint = (string) config('wireguard.endpoint');
        $port = (int) config('wireguard.listen_port');
        $serverKey = (string) config('wireguard.public_key');
        $keepalive = (int) config('wireguard.keepalive_seconds');
        $mtu = (int) config('wireguard.mtu');
        $address = (string) $router->wg_address;
        $server = (string) config('wireguard.server_address');
        $mask = $this->maskBits($subnet);

        return <<<SCRIPT

        # ---------------------------------------------------------------------
        # 1. Management tunnel
        #
        # The router dials out to us, so it needs no public address and no port
        # forwarded. It works behind NAT, behind CGNAT and on a 4G dongle.
        #
        # Three settings here decide whether the platform feels instant or feels
        # broken, and none of them is about bandwidth:
        #
        #   persistent-keepalive — a NAT mapping with no traffic through it
        #     commonly disappears in about 30 seconds, and WireGuard itself
        #     rekeys after two minutes of silence. {$keepalive}s sits inside both, so
        #     the tunnel is already up at the moment somebody pays rather than
        #     being rebuilt while they wait.
        #
        #   mtu={$mtu} — under RouterOS's 1420 default on purpose. On a PPPoE
        #     line or a 4G dongle the default is too big, and a path that
        #     swallows the "packet too large" reply turns that into a
        #     conversation that hangs rather than one that fails.
        #
        #   the mangle rules below — management traffic is never given a packet
        #     mark, so queue trees an ISP adds later to shape subscribers cannot
        #     put a provisioning command behind somebody's download.
        # ---------------------------------------------------------------------

        # The peer goes before the interface it belongs to is removed, for the same reason
        # as everywhere else here: RouterOS will not remove something still referenced.
        /interface/wireguard/peers remove [find comment="Platform management server"]
        /ip/address remove [find comment="Management address"]
        /interface/wireguard remove [find name="{$interface}"]

        /interface/wireguard
        add name="{$interface}" private-key="{$router->wg_private_key}" mtu={$mtu} comment="Managed by the platform"

        /interface/wireguard/peers
        add interface="{$interface}" \\
            public-key="{$serverKey}" \\
            endpoint-address={$endpoint} \\
            endpoint-port={$port} \\
            allowed-address={$subnet} \\
            persistent-keepalive={$keepalive}s \\
            comment="Platform management server"

        /ip/address
        add address={$address}/{$mask} interface="{$interface}" comment="Management address"

        # Management traffic is tiny and it is what a paying customer is waiting
        # on. Taken out of mangle before any marking rule sees it, so a queue
        # tree an ISP adds later to shape their subscribers cannot put a
        # provisioning command behind somebody's download.
        #
        # place-before only when there is something to place before: on a freshly
        # wiped router the list is empty, "place-before=0" names a rule that does
        # not exist, and RouterOS stops the whole file right here.
        /ip/firewall/mangle remove [find comment="platform-management"]
        :if ([:len [/ip/firewall/mangle find]] > 0) do={
            /ip/firewall/mangle add chain=prerouting in-interface="{$interface}" action=accept place-before=0 comment="platform-management"
            /ip/firewall/mangle add chain=output out-interface="{$interface}" action=accept place-before=1 comment="platform-management"
        } else={
            /ip/firewall/mangle add chain=prerouting in-interface="{$interface}" action=accept comment="platform-management"
            /ip/firewall/mangle add chain=output out-interface="{$interface}" action=accept comment="platform-management"
        }

        # Nudge the tunnel awake rather than waiting for the first keepalive, so
        # the platform can reach this router the moment the paste finishes
        # instead of up to {$keepalive} seconds later.
        :delay 2s;
        :do {
            /ping address={$server} interface="{$interface}" count=2 as-value
        } on-error={ :put "Tunnel has not come up yet — it will retry by itself."; }
        SCRIPT;
    }

    /**
     * The API, reachable down the tunnel and nowhere else.
     */
    protected function apiSection(
        Router $router,
        string $interface,
        string $user,
        string $group,
        string $password,
        string $serverAddress,
        string $subnet,
        string $localSubnet = '',
    ): string {
        // Allowed in alongside the tunnel when a bench machine has to reach this device. The
        // three places below must agree: the service decides which addresses it answers, the
        // user decides where it may log in from, and the firewall decides what arrives at all.
        // Granting one and forgetting another produces a device that refuses in a different
        // way each time and looks broken rather than closed.
        $addresses = $localSubnet === '' ? $subnet : $subnet.','.$localSubnet;

        $localRule = $localSubnet === '' ? '' : <<<RULE


        # Development allowance: this router is also reachable from {$localSubnet}.
        # Generated because wireguard.local_api_subnet is set. It must not be set on a
        # platform serving real ISPs — a router in a shop sits on somebody else's LAN, and
        # whoever else is on that LAN is not us.
        /ip/firewall/filter add chain=input protocol=tcp dst-port=8728 src-address={$localSubnet} action=accept comment="platform-api-allow-local" place-before=0
        RULE;

        $localNote = $localSubnet === '' ? '' : "\n        # Also answers {$localSubnet}, for a machine on the same bench as this router.";

        return <<<SCRIPT

        # ---------------------------------------------------------------------
        # 2. API access, tunnel only
        #
        # The service is bound to the management subnet and firewalled off
        # every other interface before it is enabled. An API reachable from the
        # internet is an admin service advertised to the world, and no amount
        # of password strength makes that a good idea.{$localNote}
        # ---------------------------------------------------------------------

        # The user is removed before the group it belongs to, or RouterOS refuses the group.
        /user remove [find name="{$user}"]
        /user/group remove [find name="{$group}"]

        # ftp is in here for one reason: RouterOS ties file writing to it, and /tool/fetch is
        # how the captive-portal login page gets onto the device. Without it the portal
        # install fails with "cannot open file: permission denied" — verified on hardware.
        #
        # policy is deliberately left out. It would let this account create users with any
        # rights at all, which is the whole of the device rather than the part we need.
        /user/group
        add name="{$group}" policy=read,write,test,api,sensitive,ftp,reboot \\
            comment="Platform provisioning: HotSpot users and portal files"

        /user
        add name="{$user}" password="{$password}" group="{$group}" \\
            address={$addresses} comment="Platform. Do not remove."

        /ip/service
        set api address={$addresses} port=8728 disabled=no
        # Nothing here speaks api-ssl, and an unused open port is a liability. It is also a
        # port that answers a TCP connection and then fails the handshake when no certificate
        # is assigned, which reads as a broken network rather than a disabled service.
        set api-ssl disabled=yes

        # The drop goes in first and the allow above it, so it never matters
        # whether the list was empty: each place-before names a rule that is
        # already there.
        /ip/firewall/filter
        remove [find comment="platform-api-allow"]
        remove [find comment="platform-api-allow-local"]
        remove [find comment="platform-api-drop"]
        :if ([:len [/ip/firewall/filter find]] > 0) do={
            /ip/firewall/filter add chain=input protocol=tcp dst-port=8728 action=drop comment="platform-api-drop" place-before=0
        } else={
            /ip/firewall/filter add chain=input protocol=tcp dst-port=8728 action=drop comment="platform-api-drop"
        }
        /ip/firewall/filter add chain=input protocol=tcp dst-port=8728 src-address={$serverAddress} action=accept comment="platform-api-allow" place-before=0{$localRule}
        SCRIPT;
    }

    /**
     * The HotSpot the customers meet.
     */
    protected function hotspotSection(
        Router $router,
        string $bridge,
        string $lan,
        string $pool,
        string $hotspot,
        string $network,
        string $gateway,
        string $poolStart,
        string $poolEnd,
        string $portalHost,
    ): string {
        $mask = $this->maskBits($network);
        $profile = $hotspot.'-profile';

        // The pages the router will serve, each carrying this router's own portal key — so a
        // script copied from one device can never point another at the wrong ISP's packages.
        // Indented to sit under the block each is written into, so the pasted script reads as
        // one thing rather than a wall that falls out of alignment halfway down.
        $pageFetches = $this->pageFetches($router, 'hotspot', '        ');
        $flashFetches = $this->pageFetches($router, 'flash/hotspot', '            ');
        $portalAddress = $this->resolve($portalHost);
        $timezone = $this->timezone((string) $router->tenant->timezone);

        return <<<SCRIPT

        # ---------------------------------------------------------------------
        # 3. HotSpot
        #
        # Check the interface below before running this. Putting the wrong port
        # into the bridge is how a router loses its own internet connection —
        # {$lan} is what customers plug into or associate with, never the
        # uplink.
        #
        # /ip/hotspot/setup cannot be scripted because it asks questions, so
        # each piece it would have built is created here explicitly.
        # ---------------------------------------------------------------------

        # Anything a previous run built is taken down first, in reverse dependency order.
        # RouterOS refuses to remove a pool a DHCP server is still using, or a profile a
        # HotSpot server still points at, so the order here is what makes a second run work
        # rather than fail halfway and leave the network in pieces.
        /ip/hotspot remove [find name="{$hotspot}"]
        /ip/hotspot/profile remove [find name="{$profile}"]
        /ip/dhcp-server remove [find name="dhcp-{$hotspot}"]
        /ip/dhcp-server/network remove [find comment="platform-hotspot"]
        /ip/pool remove [find name="{$pool}"]

        # The bridge is created only when it is missing. Removing and rebuilding one is how
        # a router whose uplink was named by mistake drops off the internet mid-script, and
        # it would take the operator's own session with it.
        :if ([:len [/interface/bridge find name="{$bridge}"]] = 0) do={
            /interface/bridge add name="{$bridge}" comment="HotSpot"
        }

        /interface/bridge/port
        remove [find interface="{$lan}"]
        add bridge="{$bridge}" interface="{$lan}"

        /ip/pool
        add name="{$pool}" ranges={$poolStart}-{$poolEnd}

        /ip/address
        remove [find interface="{$bridge}"]
        add address={$gateway}/{$mask} interface="{$bridge}" comment="HotSpot gateway"

        /ip/dhcp-server
        add name="dhcp-{$hotspot}" interface="{$bridge}" address-pool="{$pool}" \\
            lease-time=1h disabled=no
        /ip/dhcp-server/network
        add address={$network} gateway={$gateway} dns-server={$gateway} comment="platform-hotspot"

        # The router answers DNS for its own HotSpot clients, which is what makes the
        # captive-portal redirect possible in the first place.
        /ip/dns
        set allow-remote-requests=yes servers=1.1.1.1,8.8.8.8

        # login-by=http-chap,http-pap because the portal posts the credentials
        # as plain fields once payment clears — the customer never types them.
        /ip/hotspot/profile
        add name="{$profile}" hotspot-address={$gateway} \\
            login-by=http-chap,http-pap use-radius=no

        /ip/hotspot
        add name="{$hotspot}" interface="{$bridge}" address-pool="{$pool}" \\
            profile="{$profile}" addresses-per-mac=2 disabled=no

        # The walled garden is what lets a customer who has paid nothing reach
        # the page where they pay. Without it they are redirected to a portal
        # they cannot load, which looks to them like broken wifi.
        /ip/hotspot/walled-garden
        remove [find comment="platform-portal"]
        add dst-host="{$portalHost}" action=allow comment="platform-portal"

        # RouterOS has two walled gardens and they are not interchangeable. The
        # one above belongs to the HotSpot's HTTP proxy, so it only ever covers
        # port 80 — an https portal is refused outright unless it is also
        # allowed at the IP level. {$portalHost} resolved to this when the
        # script was made; regenerate it if the platform ever moves.
        /ip/hotspot/walled-garden/ip
        remove [find comment="platform-portal"]
        add dst-address={$portalAddress} action=accept comment="platform-portal"

        /ip/hotspot/walled-garden/ip
        remove [find comment="platform-dns"]
        add dst-address=1.1.1.1 action=accept comment="platform-dns"
        add dst-address=8.8.8.8 action=accept comment="platform-dns"

        # The provider's own domains, or a payment confirmation cannot reach a
        # customer still sitting unauthenticated on the portal.
        /ip/hotspot/walled-garden
        remove [find comment="platform-providers"]
        add dst-host="*.safaricom.co.ke" action=allow comment="platform-providers"
        add dst-host="*.paystack.co" action=allow comment="platform-providers"

        # Fasttrack sends established connections straight past the firewall —
        # and past the HotSpot with them. Leaving it in place is how a router
        # ends up letting customers online without a login and reporting no
        # usage for anyone.
        /ip/firewall/filter
        remove [find action=fasttrack-connection]

        # ---------------------------------------------------------------------
        # 3b. The login page
        #
        # Without this the router serves MikroTik's own page, which asks for a
        # username and password the customer has never been given. This one
        # simply hands them to the portal.
        #
        # Fetched here rather than pushed over the API afterwards, because this
        # paste runs as an administrator: the platform's own account cannot
        # write files unless it has been granted the ftp policy, and a router
        # should be finished by the time this script ends.
        # ---------------------------------------------------------------------

        :put "Downloading the HotSpot pages…";

        {$pageFetches}
        # Boards with onboard flash serve from there, and a page written only to
        # the volatile path is one that disappears at the next reboot.
        :if ([:len [/file find name="flash"]] > 0) do={
            :put "Copying them to flash…";
            {$flashFetches}
        }

        /ip/hotspot/profile
        set [find name="{$profile}"] html-directory=hotspot

        # The device stamps its own logs and expiry times, so it should agree
        # with the platform about what time it is.
        /system/clock
        set time-zone-name={$timezone}
        SCRIPT;
    }

    protected function closingSection(Router $router): string
    {
        $portalUrl = $router->portalUrl();
        $port = (int) config('wireguard.listen_port');

        return <<<SCRIPT

        # ---------------------------------------------------------------------
        # 4. Done
        #
        # Check the tunnel came up:
        #
        #   /interface/wireguard/peers print detail
        #
        # A recent "last-handshake" means the router has reached us. If it stays
        # empty, the router cannot get out to UDP port {$port} — that is the one
        # thing this arrangement still needs from the network it sits on.
        #
        # This router's captive portal:
        #   {$portalUrl}
        # ---------------------------------------------------------------------

        :put "Setup complete. Tunnel: {$router->wg_address}";
        SCRIPT;
    }

    /**
     * A DHCP range that leaves the gateway out of it.
     *
     * @return array{string, string}
     */
    protected function poolRange(string $network, string $gateway): array
    {
        $prefix = implode('.', array_slice(explode('.', explode('/', $network)[0]), 0, 3));
        $gatewayHost = (int) (explode('.', $gateway)[3] ?? 1);

        // Start clear of the gateway rather than immediately after it, so an operator has
        // room for a printer or an access point with a fixed address.
        return [$prefix.'.'.max(10, $gatewayHost + 9), $prefix.'.254'];
    }

    /**
     * The fetch lines for every page the platform serves this router.
     *
     * One per page rather than a loop, because an operator watching a paste scroll past wants
     * to see which file failed — and RouterOS carries on after a failed fetch, so a silent
     * loop would leave a device half-configured with nothing to point at.
     *
     * The delay is not superstition: a board with slow flash drops fetches issued back to back.
     */
    protected function pageFetches(Router $router, string $directory, string $indent = ''): string
    {
        $lines = [];

        foreach (array_keys(HotspotPageController::PAGES) as $page) {
            $url = route('portal.mikrotik', ['portalKey' => $router->portal_key, 'page' => $page]);
            $mode = str_starts_with($url, 'https://') ? 'https' : 'http';

            $lines[] = '/tool/fetch url="'.$url.'" mode='.$mode.' dst-path='.$directory.'/'.$page.' check-certificate=no';
            $lines[] = ':delay 2s;';
        }

        // The first line lands where the heredoc already put it, so only the rest are
        // indented — a heredoc cannot start an interpolated block at column zero.
        return implode("\n".$indent, $lines);
    }

    /**
     * The name customers will look for on their phone.
     *
     * Defaults to what the ISP calls itself, because a wifi list is not the place to meet
     * "MikroTik". Spaces are fine — this is read by people, not by RouterOS — but quotes and
     * backslashes would close the string it sits inside.
     */
    protected function ssid(?string $value, Router $router): string
    {
        $ssid = trim((string) ($value ?: PortalSetting::current()->displayName($router->tenant)));

        // Held to what a wifi name is actually made of. Stripping only quotes would be enough
        // to keep the script safe — the value sits inside them — but it would still let an
        // SSID carry something that reads like a command, and a script nobody can skim is a
        // script nobody can check.
        $ssid = trim((string) preg_replace("/[^A-Za-z0-9 _\-.'&+()]/", '', $ssid));

        // 32 bytes is the limit the standard sets, and RouterOS refuses anything longer.
        return mb_strimwidth($ssid, 0, 32) ?: 'Wifi';
    }

    /**
     * A timezone name RouterOS will accept.
     *
     * Not run through the general name sanitiser: that strips the slash, and "AfricaNairobi"
     * is not a place.
     */
    protected function timezone(string $value): string
    {
        return preg_match('#^[A-Za-z]+/[A-Za-z_+\-0-9]+$#', $value) === 1 ? $value : 'Africa/Nairobi';
    }

    /**
     * The platform's address, for the walled-garden rule that needs one.
     *
     * Resolved when the script is generated, because the router cannot be asked to do it: its
     * DNS is answered by the HotSpot, which is the very thing being configured.
     */
    protected function resolve(string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        $address = gethostbyname($host);

        // gethostbyname hands back what it was given when it fails. Rather than write a
        // hostname into a rule that needs an address — which RouterOS rejects — fall back to
        // the whole internet on that comment, which the dst-host rule above already narrows.
        return filter_var($address, FILTER_VALIDATE_IP) !== false ? $address : '0.0.0.0/0';
    }

    /**
     * An address or CIDR range RouterOS will accept, or nothing at all.
     *
     * This is written straight into a firewall rule and a service binding, so anything that is
     * not plainly an IPv4 address or range is refused rather than sanitised into something
     * subtly different. A typo that silently became 0.0.0.0/0 would open the API to everyone
     * on the strength of a misplaced character.
     */
    protected function subnet(mixed $value): string
    {
        $candidate = trim((string) $value);

        if ($candidate === '') {
            return '';
        }

        [$address, $bits] = array_pad(explode('/', $candidate, 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return '';
        }

        if ($bits === null) {
            return $address;
        }

        return preg_match('/^\d{1,2}$/', $bits) === 1 && (int) $bits <= 32 ? $address.'/'.$bits : '';
    }

    protected function maskBits(string $cidr): string
    {
        return str_contains($cidr, '/') ? explode('/', $cidr)[1] : '24';
    }

    /**
     * Names go inside RouterOS quotes, so anything that could close one is refused.
     *
     * These come from an operator's own form, but a name carrying a quote would produce a
     * script that does something other than it appears to.
     */
    protected function name(mixed $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '', (string) $value);

        return $clean === '' ? 'platform' : $clean;
    }
}
