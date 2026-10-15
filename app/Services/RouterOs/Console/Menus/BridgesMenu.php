<?php

namespace App\Services\RouterOs\Console\Menus;

use App\Models\Router;
use App\Services\RouterOs\Console\ConsoleMenu;
use App\Services\RouterOs\Console\Patterns;
use App\Services\RouterOs\HotspotInstaller;

/**
 * /interface/bridge — groups of ports that act as one network: a customer LAN made of several
 * ports and the wifi, a VLAN trunk, a separate network for a second HotSpot.
 *
 * Two are the platform's and kept as they are. The technician bridge is the way into the
 * router that survives every other mistake; the HotSpot bridge is built and rebuilt by HotSpot
 * setup, and removing it from under a running HotSpot takes every customer offline. Their
 * *ports* can still be changed in Bridge ports.
 */
class BridgesMenu extends ConsoleMenu
{
    public function key(): string
    {
        return 'bridges';
    }

    public function label(): string
    {
        return __('Bridges');
    }

    public function description(): string
    {
        return __('Groups of ports that act as one network. Make one here, then put ports and wifi into it under Bridge ports.');
    }

    public function path(): string
    {
        return '/interface/bridge';
    }

    public function icon(): string
    {
        return 'square-3-stack-3d';
    }

    public function group(): string
    {
        return __('Interfaces');
    }

    public function columns(): array
    {
        return [
            __('Name') => 'name',
            __('MAC address') => 'mac-address',
            __('Protocol') => 'protocol-mode',
            __('Running') => 'running',
            __('Comment') => 'comment',
        ];
    }

    public function fields(): array
    {
        return [
            'name' => ['label' => __('Name'), 'rules' => ['required', Patterns::NAME, 'max:64'], 'placeholder' => 'bridge-office'],
            'protocol-mode' => ['label' => __('Loop protection'), 'rules' => ['nullable', 'in:rstp,stp,none,mstp'], 'type' => 'select', 'options' => [
                'rstp' => __('RSTP (recommended)'),
                'none' => __('None'),
                'stp' => 'STP',
                'mstp' => 'MSTP',
            ], 'description' => __('Stops a cable plugged in twice from flooding the network.')],
            'comment' => ['label' => __('Comment'), 'rules' => ['nullable', 'string', 'max:120'], 'placeholder' => __('Office network')],
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

    public function protects(array $row, Router $router): ?string
    {
        $name = (string) ($row['name'] ?? '');

        if ($name === 'bridge-tech') {
            return __('The technician bridge: plug a laptop into any of its ports and Winbox answers at 192.168.88.1. Kept so the router can always be reached.');
        }

        if ($name === HotspotInstaller::BRIDGE) {
            return __('The HotSpot runs on this bridge. Change it with HotSpot setup; its ports can be changed under Bridge ports.');
        }

        return parent::protects($row, $router);
    }
}
