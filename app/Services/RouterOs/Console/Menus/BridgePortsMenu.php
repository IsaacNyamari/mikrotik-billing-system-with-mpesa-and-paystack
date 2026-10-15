<?php

namespace App\Services\RouterOs\Console\Menus;

use App\Models\Router;
use App\Services\RouterOs\Console\ConsoleMenu;
use App\Services\RouterOs\Console\Patterns;

/**
 * /interface/bridge/port — which interface belongs to which bridge.
 *
 * Moving a port is the everyday change: give the customer HotSpot a second port, take a port
 * out for a fixed-address device, put a new access point on the office bridge. The uplink and
 * the management tunnel can never be put in a bridge from here: bridged, they stop being the
 * router's way out, and the platform loses the router with them.
 */
class BridgePortsMenu extends ConsoleMenu
{
    public function key(): string
    {
        return 'bridge-ports';
    }

    public function label(): string
    {
        return __('Bridge ports');
    }

    public function description(): string
    {
        return __('Which ports and radios belong to which bridge. An interface can be in one bridge at a time; to move it, remove it here and add it to the other.');
    }

    public function path(): string
    {
        return '/interface/bridge/port';
    }

    public function icon(): string
    {
        return 'link';
    }

    public function group(): string
    {
        return __('Interfaces');
    }

    public function columns(): array
    {
        return [
            __('Interface') => 'interface',
            __('Bridge') => 'bridge',
            __('PVID') => 'pvid',
            __('Comment') => 'comment',
        ];
    }

    public function fields(): array
    {
        return [
            'interface' => ['label' => __('Interface'), 'rules' => ['required', Patterns::INTERFACE, 'max:64'], 'placeholder' => 'ether3', 'description' => __('A port or radio from Interfaces. Not the uplink.'), 'create_only' => true],
            'bridge' => ['label' => __('Bridge'), 'rules' => ['required', Patterns::NAME, 'max:64'], 'placeholder' => 'bridge-hotspot', 'description' => __('A name from Bridges.')],
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

    /**
     * Checked on rows that exist and on rows about to be added, so the uplink cannot be
     * bridged in the first place.
     */
    public function protects(array $row, Router $router): ?string
    {
        $options = $router->setupOptions();
        $interface = (string) ($row['interface'] ?? '');

        if ($interface !== '' && $interface === ($options['wan_interface'] ?? null)) {
            return __(':interface is the uplink. In a bridge it stops being the router\'s way out, and the platform loses the router.', ['interface' => $interface]);
        }

        if ($interface !== '' && $interface === ($options['interface_name'] ?? null)) {
            return __(':interface is the management tunnel and cannot be bridged.', ['interface' => $interface]);
        }

        return parent::protects($row, $router);
    }
}
