<?php

namespace App\Services\RouterOs\Console\Menus;

use App\Services\RouterOs\Console\ConsoleMenu;
use App\Services\RouterOs\Console\Patterns;

/**
 * /ip/dns/static — names the router answers itself, for a local server or to block a site.
 */
class DnsStaticMenu extends ConsoleMenu
{
    public function key(): string
    {
        return 'dns';
    }

    public function label(): string
    {
        return __('DNS entries');
    }

    public function description(): string
    {
        return __('Names the router answers itself: a local server, or a site pointed nowhere to block it.');
    }

    public function path(): string
    {
        return '/ip/dns/static';
    }

    public function icon(): string
    {
        return 'at-symbol';
    }

    public function group(): string
    {
        return __('IP');
    }

    public function columns(): array
    {
        return [
            __('Name') => 'name',
            __('Address') => 'address',
            __('TTL') => 'ttl',
            __('Comment') => 'comment',
        ];
    }

    public function fields(): array
    {
        return [
            'name' => ['label' => __('Name'), 'rules' => ['required', Patterns::HOST, 'max:128'], 'placeholder' => 'server.local'],
            'address' => ['label' => __('Address'), 'rules' => ['required', 'ipv4'], 'placeholder' => '192.168.10.5'],
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
