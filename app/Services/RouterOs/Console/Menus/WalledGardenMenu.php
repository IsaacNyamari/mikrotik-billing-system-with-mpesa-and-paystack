<?php

namespace App\Services\RouterOs\Console\Menus;

use App\Services\RouterOs\Console\ConsoleMenu;
use App\Services\RouterOs\Console\Patterns;

/**
 * /ip/hotspot/walled-garden — sites customers can open before they have paid.
 *
 * The platform's own entries (the portal, the payment providers) carry a platform- comment and
 * are kept out of reach: removing one is how a HotSpot ends up redirecting everybody to a page
 * they cannot load.
 */
class WalledGardenMenu extends ConsoleMenu
{
    public function key(): string
    {
        return 'walled-garden';
    }

    public function label(): string
    {
        return __('Walled garden');
    }

    public function description(): string
    {
        return __('Sites customers can open before logging in. The portal and payment entries are the platform\'s and stay put.');
    }

    public function path(): string
    {
        return '/ip/hotspot/walled-garden';
    }

    public function icon(): string
    {
        return 'globe-alt';
    }

    public function group(): string
    {
        return __('HotSpot');
    }

    public function columns(): array
    {
        return [
            __('Host') => 'dst-host',
            __('Action') => 'action',
            __('Comment') => 'comment',
        ];
    }

    public function fields(): array
    {
        return [
            'dst-host' => ['label' => __('Host'), 'rules' => ['required', Patterns::HOST, 'max:128'], 'placeholder' => '*.example.co.ke'],
            'action' => ['label' => __('Action'), 'rules' => ['required', 'in:allow,deny'], 'type' => 'select', 'options' => ['allow' => __('Allow'), 'deny' => __('Deny')]],
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
