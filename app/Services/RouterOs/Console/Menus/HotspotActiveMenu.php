<?php

namespace App\Services\RouterOs\Console\Menus;

use App\Services\RouterOs\Console\ConsoleMenu;

/**
 * /ip/hotspot/active — who is logged in to the HotSpot right now. Removing a session logs that
 * device out; its package is untouched and it can log straight back in.
 */
class HotspotActiveMenu extends ConsoleMenu
{
    public function key(): string
    {
        return 'hotspot-active';
    }

    public function label(): string
    {
        return __('HotSpot online');
    }

    public function description(): string
    {
        return __('Devices logged in right now. Logging one out does not touch their package; they can sign straight back in.');
    }

    public function path(): string
    {
        return '/ip/hotspot/active';
    }

    public function icon(): string
    {
        return 'wifi';
    }

    public function group(): string
    {
        return __('HotSpot');
    }

    public function columns(): array
    {
        return [
            __('User') => 'user',
            __('Address') => 'address',
            __('MAC address') => 'mac-address',
            __('Uptime') => 'uptime',
            __('Time left') => 'session-time-left',
            __('Login by') => 'login-by',
        ];
    }

    public function canRemove(): bool
    {
        return true;
    }

    public function removeLabel(): string
    {
        return __('Log out');
    }

    public function protectsDynamic(): bool
    {
        return false;
    }
}
