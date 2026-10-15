<?php

namespace App\Services\RouterOs\Console\Menus;

use App\Services\RouterOs\Console\ConsoleMenu;

/**
 * /ip/hotspot/host — every device the HotSpot can see, logged in or not. The first place to
 * look when a customer says they are connected to the wifi and nothing happens.
 */
class HotspotHostsMenu extends ConsoleMenu
{
    public function key(): string
    {
        return 'hotspot-hosts';
    }

    public function label(): string
    {
        return __('HotSpot devices');
    }

    public function description(): string
    {
        return __('Every device on the HotSpot network, whether logged in or not. Removing one makes it start over at the login page.');
    }

    public function path(): string
    {
        return '/ip/hotspot/host';
    }

    public function icon(): string
    {
        return 'device-phone-mobile';
    }

    public function group(): string
    {
        return __('HotSpot');
    }

    public function columns(): array
    {
        return [
            __('MAC address') => 'mac-address',
            __('Address') => 'address',
            __('Logged in') => 'authorized',
            __('Bypassed') => 'bypassed',
            __('Idle') => 'idle-time',
            __('Comment') => 'comment',
        ];
    }

    public function canRemove(): bool
    {
        return true;
    }

    public function protectsDynamic(): bool
    {
        return false;
    }
}
