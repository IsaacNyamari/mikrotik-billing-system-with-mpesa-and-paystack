<?php

namespace App\Services\Provisioning;

use App\Models\Router;
use App\Support\PublicUrl;
use Illuminate\Support\Str;

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

        // Wiping the device is deliberate and never the default. It is the right answer for a
        // router straight out of its box, and the wrong one for a router already carrying an
        // ISP's own configuration.
        $wipe = ($options['wipe'] ?? $defaults['wipe'] ?? false) === true;

        $network = (string) ($options['hotspot_network'] ?? $defaults['hotspot_network']);
        $gateway = (string) ($options['hotspot_gateway'] ?? $defaults['hotspot_gateway']);
        [$poolStart, $poolEnd] = $this->poolRange($network, $gateway);

        $serverAddress = (string) config('wireguard.server_address');
        $subnet = (string) config('wireguard.subnet');
        $portalHost = (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');

        // A fresh API password every time a script is generated. The old one stops working
        // the moment this runs, which is the point: a script that leaked is revoked by
        // generating another.
        $apiPassword = $options['api_password'] ?? Str::password(24, symbols: false);

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
        ];

        $router->api_username = $apiUser;
        $router->api_password = $apiPassword;
        // From here on the platform talks to this device down the tunnel, not over the
        // internet. This is the line that makes the whole arrangement work.
        $router->host = (string) $router->wg_address;
        $router->api_port = 8728;
        $router->uses_tls = false;
        $router->save();

        // Wiping reboots the device, which throws away whatever is still being pasted. So the
        // operator pastes a short bootstrap instead: it downloads the real configuration to
        // the router, then resets with run-after-reset pointing at it. What comes back is a
        // device configured from nothing.
        if ($wipe) {
            return $this->bootstrap($router);
        }

        $lines = [];

        $lines[] = $this->banner($router, $portalHost);
        $lines[] = $this->tunnelSection($router, $wgInterface, $subnet);
        $lines[] = $this->apiSection($router, $wgInterface, $apiUser, $apiGroup, $apiPassword, $serverAddress, $subnet);

        if ($withHotspot) {
            $lines[] = $this->hotspotSection($router, $bridge, $lan, $pool, $hotspot, $network, $gateway, $poolStart, $poolEnd, $portalHost);
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

        $wgInterface = $this->name($options['interface_name']);
        $bridge = $this->name($options['bridge_name']);
        $hotspot = $this->name($options['hotspot_name']);
        $pool = $this->name($options['pool_name']);
        $lan = $this->name($options['lan_interface']);
        $wan = $this->name($options['wan_interface'] ?? 'ether1');
        $apiUser = $this->name($options['api_username']);
        $apiGroup = $this->name($options['api_group']);
        $withHotspot = ($options['with_hotspot'] ?? true) === true;

        $network = (string) $options['hotspot_network'];
        $gateway = (string) $options['hotspot_gateway'];
        [$poolStart, $poolEnd] = $this->poolRange($network, $gateway);

        $serverAddress = (string) config('wireguard.server_address');
        $subnet = (string) config('wireguard.subnet');
        $portalHost = (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');

        $lines = [
            $this->resetPreamble($router, $wan),
            $this->tunnelSection($router, $wgInterface, $subnet),
            $this->apiSection($router, $wgInterface, $apiUser, $apiGroup, (string) $router->api_password, $serverAddress, $subnet),
        ];

        if ($withHotspot) {
            $lines[] = $this->hotspotSection($router, $bridge, $lan, $pool, $hotspot, $network, $gateway, $poolStart, $poolEnd, $portalHost);
        }

        $lines[] = $this->closingSection($router);

        return implode("\n", $lines)."\n";
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

        return <<<SCRIPT
        # =====================================================================
        #  {$router->name} — WIPE AND REBUILD
        #
        #  This ERASES everything on the router and builds it again from
        #  nothing. There is no undo and no backup kept.
        #
        #  Before you paste it, be certain of two things:
        #
        #    * {$wan} is the port the router's internet comes in on.
        #    * {$lan} is the port your customers connect to.
        #
        #  If those are the wrong way round the router will come back with no
        #  way to reach the internet, and the only fix is to walk to it with a
        #  cable. Check them in /interface print first.
        #
        #  The link below works once and expires in {$minutes} minutes.
        # =====================================================================

        # 1. Fetch the configuration onto the router. It has to be on the device
        #    before the reset, because the reset reboots immediately and takes
        #    the rest of this paste with it.
        /tool/fetch url="{$url}" mode={$mode} dst-path=platform-setup.rsc check-certificate=no

        # 2. Make sure it actually arrived. Resetting without it would leave the
        #    router wiped, unconfigured and unreachable.
        :if ([:len [/file find name="platform-setup.rsc"]] = 0) do={
            :error "Download failed — the router has no internet, or cannot reach the platform. Nothing has been changed."
        }

        # 3. Wipe, then run what we just downloaded on the way back up.
        #    no-defaults means the MikroTik defaults are not restored either:
        #    the file below is the entire configuration.
        :put "Resetting. The router will reboot and come back in a minute or two.";
        /system/reset-configuration no-defaults=yes skip-backup=yes run-after-reset=platform-setup.rsc
        SCRIPT;
    }

    /**
     * What a wiped router needs before anything else works.
     *
     * A reset takes away the uplink, NAT and every way in. Nothing else in this script would
     * put them back, and a router that cannot reach the internet cannot reach us either.
     */
    protected function resetPreamble(Router $router, string $wan): string
    {
        $identity = $this->name($router->slug);

        return <<<SCRIPT
        # =====================================================================
        #  {$router->name}
        #  Built from nothing after a reset. Do not edit — regenerate instead.
        # =====================================================================

        :delay 5s;
        /system/identity set name="{$identity}"

        # ---------------------------------------------------------------------
        # 0. The way out
        #
        # A reset leaves no DHCP client and no NAT, so this comes first: every
        # other line here depends on the router being able to reach us.
        # ---------------------------------------------------------------------

        /ip/dhcp-client
        add interface="{$wan}" disabled=no add-default-route=yes use-peer-dns=yes comment="Uplink"

        /ip/firewall/nat
        add chain=srcnat out-interface="{$wan}" action=masquerade comment="Uplink"

        # Give DHCP a moment to actually get an address before anything tries to
        # use the internet.
        :delay 10s;

        # ---------------------------------------------------------------------
        # 0b. Keeping the router itself safe
        #
        # A wiped MikroTik has no firewall at all. These are the rules the
        # stock configuration would have given it, and without them the device
        # is answering the whole internet.
        # ---------------------------------------------------------------------

        /ip/firewall/filter
        add chain=input connection-state=established,related action=accept comment="platform-base"
        add chain=input connection-state=invalid action=drop comment="platform-base"
        add chain=input protocol=icmp action=accept comment="platform-base"
        add chain=forward connection-state=established,related action=accept comment="platform-base"
        add chain=forward connection-state=invalid action=drop comment="platform-base"

        /ip/service
        set telnet disabled=yes
        set ftp disabled=yes
        set www disabled=yes
        set api-ssl disabled=yes
        SCRIPT;
    }

    /**
     * A fresh single-use link for this router to fetch its configuration from.
     *
     * Reissued every time a wipe script is generated, which also invalidates the previous one:
     * a link that was copied into a chat message stops working the moment a new script is
     * made.
     */
    protected function issueProvisionToken(Router $router): string
    {
        $token = Str::random(48);

        $router->provision_token = $token;
        $router->provision_token_expires_at = now()->addMinutes((int) config('wireguard.provision_link_minutes'));
        $router->provision_fetched_at = null;
        // Saved here rather than by the caller: a token written into a script but not into the
        // database is a link that 404s the moment the router tries it.
        $router->save();

        return $token;
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
        $address = (string) $router->wg_address;
        $mask = $this->maskBits($subnet);

        return <<<SCRIPT

        # ---------------------------------------------------------------------
        # 1. Management tunnel
        #
        # The router dials out to us, so it needs no public address and no port
        # forwarded. It works behind NAT, behind CGNAT and on a 4G dongle.
        #
        # persistent-keepalive is not optional here: a NAT mapping with no
        # traffic through it commonly disappears in about 30 seconds, and the
        # platform would then only reach this router while it happened to be
        # talking to us.
        # ---------------------------------------------------------------------

        # The peer goes before the interface it belongs to is removed, for the same reason
        # as everywhere else here: RouterOS will not remove something still referenced.
        /interface/wireguard/peers remove [find comment="Platform management server"]
        /ip/address remove [find comment="Management address"]
        /interface/wireguard remove [find name="{$interface}"]

        /interface/wireguard
        add name="{$interface}" private-key="{$router->wg_private_key}" comment="Managed by the platform"

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
    ): string {
        return <<<SCRIPT

        # ---------------------------------------------------------------------
        # 2. API access, tunnel only
        #
        # The service is bound to the management subnet and firewalled off
        # every other interface before it is enabled. An API reachable from the
        # internet is an admin service advertised to the world, and no amount
        # of password strength makes that a good idea.
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
        add name="{$group}" policy=read,write,test,api,sensitive,ftp \\
            comment="Platform provisioning: HotSpot users and portal files"

        /user
        add name="{$user}" password="{$password}" group="{$group}" \\
            address={$subnet} comment="Platform. Do not remove."

        /ip/service
        set api address={$subnet} port=8728 disabled=no
        # Nothing here speaks api-ssl, and an unused open port is a liability.
        set api-ssl disabled=yes

        /ip/firewall/filter
        remove [find comment="platform-api-allow"]
        remove [find comment="platform-api-drop"]
        add chain=input protocol=tcp dst-port=8728 src-address={$serverAddress} \\
            action=accept comment="platform-api-allow" place-before=0
        add chain=input protocol=tcp dst-port=8728 \\
            action=drop comment="platform-api-drop" place-before=1
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
        #
        # dst-host covers both ports, so one entry is enough.
        /ip/hotspot/walled-garden
        remove [find comment="platform-portal"]
        add dst-host="{$portalHost}" action=allow comment="platform-portal"

        /ip/hotspot/walled-garden/ip
        remove [find comment="platform-dns"]
        add dst-address=1.1.1.1 action=accept comment="platform-dns"
        add dst-address=8.8.8.8 action=accept comment="platform-dns"
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
