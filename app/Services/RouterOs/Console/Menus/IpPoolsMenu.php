<?php

namespace App\Services\RouterOs\Console\Menus;

use App\Services\RouterOs\Console\ConsoleMenu;
use App\Services\RouterOs\Console\Patterns;

/**
 * /ip/pool — ranges of addresses handed out by DHCP, the HotSpot and PPPoE.
 */
class IpPoolsMenu extends ConsoleMenu
{
    public function key(): string
    {
        return 'ip-pools';
    }

    public function label(): string
    {
        return __('Address pools');
    }

    public function description(): string
    {
        return __('Ranges of addresses handed out to devices. A PPP profile names one of these as its remote address.');
    }

    public function path(): string
    {
        return '/ip/pool';
    }

    public function icon(): string
    {
        return 'queue-list';
    }

    public function group(): string
    {
        return __('IP');
    }

    public function columns(): array
    {
        return [
            __('Name') => 'name',
            __('Ranges') => 'ranges',
            __('Next pool') => 'next-pool',
            __('Comment') => 'comment',
        ];
    }

    public function fields(): array
    {
        return [
            'name' => ['label' => __('Name'), 'rules' => ['required', Patterns::NAME, 'max:64'], 'placeholder' => 'pppoe-pool'],
            'ranges' => ['label' => __('Ranges'), 'rules' => ['required', Patterns::RANGES, 'max:255'], 'placeholder' => '10.20.0.10-10.20.0.254'],
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
}
