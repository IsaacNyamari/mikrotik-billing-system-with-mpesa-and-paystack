<?php

namespace App\Services\RouterOs\Console\Menus;

use App\Services\RouterOs\Console\ConsoleMenu;

/**
 * /ppp/active — who is connected over PPPoE right now. Removing a row disconnects that line;
 * its CPE will normally redial within seconds.
 */
class PppActiveMenu extends ConsoleMenu
{
    public function key(): string
    {
        return 'ppp-active';
    }

    public function label(): string
    {
        return __('PPP connected');
    }

    public function description(): string
    {
        return __('PPPoE lines connected right now. Disconnecting one makes its router dial again, which is the usual cure for a stuck line.');
    }

    public function path(): string
    {
        return '/ppp/active';
    }

    public function icon(): string
    {
        return 'signal';
    }

    public function group(): string
    {
        return __('PPP');
    }

    public function columns(): array
    {
        return [
            __('Name') => 'name',
            __('Service') => 'service',
            __('Address') => 'address',
            __('Caller') => 'caller-id',
            __('Uptime') => 'uptime',
        ];
    }

    public function canRemove(): bool
    {
        return true;
    }

    public function removeLabel(): string
    {
        return __('Disconnect');
    }

    public function protectsDynamic(): bool
    {
        return false;
    }
}
