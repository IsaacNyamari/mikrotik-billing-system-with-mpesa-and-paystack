<?php

namespace App\Services\RouterOs\Console\Menus;

use App\Models\Router;
use App\Services\RouterOs\Console\ConsoleMenu;

/**
 * /interface — every port, radio, bridge and tunnel on the device.
 *
 * Interfaces are created in their own type's menu (a bridge under /interface/bridge, a VLAN
 * under /interface/vlan), never here, so this screen only labels and switches them.
 */
class InterfacesMenu extends ConsoleMenu
{
    public function key(): string
    {
        return 'interfaces';
    }

    public function label(): string
    {
        return __('Interfaces');
    }

    public function description(): string
    {
        return __('Every port, radio, bridge and tunnel on the router. Disabling one takes down whatever runs over it.');
    }

    public function path(): string
    {
        return '/interface';
    }

    public function icon(): string
    {
        return 'arrows-right-left';
    }

    public function group(): string
    {
        return __('Interfaces');
    }

    public function columns(): array
    {
        return [
            __('Name') => 'name',
            __('Type') => 'type',
            __('Running') => 'running',
            __('MAC address') => 'mac-address',
            __('MTU') => 'actual-mtu',
            __('Comment') => 'comment',
        ];
    }

    public function fields(): array
    {
        return [
            'comment' => [
                'label' => __('Comment'),
                'rules' => ['nullable', 'string', 'max:120'],
                'placeholder' => __('e.g. Uplink from Safaricom'),
            ],
        ];
    }

    public function canToggle(): bool
    {
        return true;
    }

    /**
     * The uplink and the tunnel, on top of anything the setup script marked.
     *
     * Both are the platform's only road to this router. Disabling either from a web page
     * succeeds, and then the page — and every payment that should provision a login here —
     * can never reach the device again.
     */
    public function protects(array $row, Router $router): ?string
    {
        $options = $router->setupOptions();
        $name = (string) ($row['name'] ?? '');

        if ($name !== '' && $name === ($options['interface_name'] ?? null)) {
            return __('This is the management tunnel. The platform reaches this router through it.');
        }

        if ($name !== '' && $name === ($options['wan_interface'] ?? null)) {
            return __('This is the uplink. Without it the router cannot reach the internet or the platform.');
        }

        return parent::protects($row, $router);
    }
}
