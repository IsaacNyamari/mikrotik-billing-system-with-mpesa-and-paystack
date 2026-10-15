<?php

namespace App\Services\RouterOs\Console\Menus;

use App\Services\RouterOs\Console\ConsoleMenu;
use App\Services\RouterOs\Console\Patterns;

/**
 * /ip/firewall/nat — port forwards in, masquerade out.
 *
 * Forwarding a port is the usual reason an ISP opens this: a customer's CCTV recorder or game
 * server. New rules go at the end of the list, which for NAT is where they belong — the
 * platform's own masquerade matches only traffic leaving the uplink.
 */
class FirewallNatMenu extends ConsoleMenu
{
    public function key(): string
    {
        return 'firewall-nat';
    }

    public function label(): string
    {
        return __('NAT and port forwards');
    }

    public function description(): string
    {
        return __('Forward a port to a device inside the network, or masquerade a network out to the internet.');
    }

    public function path(): string
    {
        return '/ip/firewall/nat';
    }

    public function icon(): string
    {
        return 'arrows-pointing-in';
    }

    public function group(): string
    {
        return __('Firewall');
    }

    public function columns(): array
    {
        return [
            __('Chain') => 'chain',
            __('Action') => 'action',
            __('Protocol') => 'protocol',
            __('Dst. port') => 'dst-port',
            __('In interface') => 'in-interface',
            __('To address') => 'to-addresses',
            __('To port') => 'to-ports',
            __('Comment') => 'comment',
        ];
    }

    public function fields(): array
    {
        return [
            'chain' => ['label' => __('Chain'), 'rules' => ['required', 'in:dstnat,srcnat'], 'type' => 'select', 'options' => ['dstnat' => __('dstnat: traffic coming in (port forward)'), 'srcnat' => __('srcnat: traffic going out')]],
            'action' => ['label' => __('Action'), 'rules' => ['required', 'in:dst-nat,masquerade,src-nat,netmap,redirect,accept'], 'type' => 'select', 'options' => ['dst-nat' => 'dst-nat', 'masquerade' => 'masquerade', 'src-nat' => 'src-nat', 'redirect' => 'redirect', 'netmap' => 'netmap', 'accept' => 'accept']],
            'protocol' => ['label' => __('Protocol'), 'rules' => ['nullable', 'in:tcp,udp,icmp'], 'type' => 'select', 'options' => ['' => __('Any'), 'tcp' => 'TCP', 'udp' => 'UDP', 'icmp' => 'ICMP']],
            'dst-port' => ['label' => __('Destination port'), 'rules' => ['nullable', 'required_with:form.to_ports', Patterns::PORTS], 'placeholder' => '8080'],
            'src-address' => ['label' => __('Source address'), 'rules' => ['nullable', Patterns::ADDRESS]],
            'in-interface' => ['label' => __('In interface'), 'rules' => ['nullable', Patterns::INTERFACE, 'max:64'], 'placeholder' => 'ether1'],
            'out-interface' => ['label' => __('Out interface'), 'rules' => ['nullable', Patterns::INTERFACE, 'max:64']],
            'to-addresses' => ['label' => __('To address'), 'rules' => ['nullable', 'ipv4'], 'placeholder' => '10.10.10.20'],
            'to-ports' => ['label' => __('To port'), 'rules' => ['nullable', Patterns::PORTS], 'placeholder' => '80'],
            'comment' => ['label' => __('Comment'), 'rules' => ['nullable', 'string', 'max:120'], 'placeholder' => __('CCTV for Mama Njeri')],
        ];
    }

    public function canAdd(): bool
    {
        return true;
    }

    public function canRemove(): bool
    {
        return true;
    }

    public function canToggle(): bool
    {
        return true;
    }
}
