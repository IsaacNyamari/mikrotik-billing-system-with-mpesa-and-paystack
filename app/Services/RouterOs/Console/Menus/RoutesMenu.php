<?php

namespace App\Services\RouterOs\Console\Menus;

use App\Services\RouterOs\Console\ConsoleMenu;
use App\Services\RouterOs\Console\Patterns;

/**
 * /ip/route — where traffic for each destination is sent. Routes the router learned itself
 * (connected networks, the DHCP default) are shown and left alone.
 */
class RoutesMenu extends ConsoleMenu
{
    public function key(): string
    {
        return 'routes';
    }

    public function label(): string
    {
        return __('Routes');
    }

    public function description(): string
    {
        return __('Where traffic for each destination goes. A wrong default route takes the router off the internet, and the platform with it.');
    }

    public function path(): string
    {
        return '/ip/route';
    }

    public function icon(): string
    {
        return 'arrow-trending-up';
    }

    public function group(): string
    {
        return __('IP');
    }

    public function columns(): array
    {
        return [
            __('Destination') => 'dst-address',
            __('Gateway') => 'gateway',
            __('Distance') => 'distance',
            __('Active') => 'active',
            __('Comment') => 'comment',
        ];
    }

    public function fields(): array
    {
        return [
            'dst-address' => ['label' => __('Destination'), 'rules' => ['required', Patterns::CIDR], 'placeholder' => '192.168.50.0/24'],
            'gateway' => ['label' => __('Gateway'), 'rules' => ['required', 'regex:/^[A-Za-z0-9.\-_%]+$/', 'max:64'], 'placeholder' => '10.0.0.1'],
            'distance' => ['label' => __('Distance'), 'rules' => ['nullable', 'integer', 'min:1', 'max:255'], 'placeholder' => '1'],
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
