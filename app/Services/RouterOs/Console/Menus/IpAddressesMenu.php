<?php

namespace App\Services\RouterOs\Console\Menus;

use App\Services\RouterOs\Console\ConsoleMenu;
use App\Services\RouterOs\Console\Patterns;

/**
 * /ip/address — the router's own addresses, one per network it is on.
 */
class IpAddressesMenu extends ConsoleMenu
{
    public function key(): string
    {
        return 'ip-addresses';
    }

    public function label(): string
    {
        return __('Addresses');
    }

    public function description(): string
    {
        return __('The router\'s own address on each network. The uplink and the management tunnel are kept as they are.');
    }

    public function path(): string
    {
        return '/ip/address';
    }

    public function icon(): string
    {
        return 'map-pin';
    }

    public function group(): string
    {
        return __('IP');
    }

    public function columns(): array
    {
        return [
            __('Address') => 'address',
            __('Network') => 'network',
            __('Interface') => 'interface',
            __('Comment') => 'comment',
        ];
    }

    public function fields(): array
    {
        return [
            'address' => ['label' => __('Address'), 'rules' => ['required', Patterns::CIDR], 'placeholder' => '192.168.10.1/24'],
            'interface' => ['label' => __('Interface'), 'rules' => ['required', Patterns::INTERFACE, 'max:64'], 'placeholder' => 'ether2'],
            'comment' => ['label' => __('Comment'), 'rules' => ['nullable', 'string', 'max:120']],
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
