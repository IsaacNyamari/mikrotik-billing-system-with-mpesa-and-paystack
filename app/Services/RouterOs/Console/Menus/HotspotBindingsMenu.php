<?php

namespace App\Services\RouterOs\Console\Menus;

use App\Services\RouterOs\Console\ConsoleMenu;
use App\Services\RouterOs\Console\Patterns;

/**
 * /ip/hotspot/ip-binding — devices let through without a login, or kept out entirely.
 *
 * The office printer, the CCTV recorder, the owner's own phone are bypassed; a device that
 * keeps abusing the network is blocked.
 */
class HotspotBindingsMenu extends ConsoleMenu
{
    public function key(): string
    {
        return 'hotspot-bindings';
    }

    public function label(): string
    {
        return __('IP bindings');
    }

    public function description(): string
    {
        return __('Let a device through without logging in (bypassed), or keep it off the HotSpot altogether (blocked).');
    }

    public function path(): string
    {
        return '/ip/hotspot/ip-binding';
    }

    public function icon(): string
    {
        return 'link';
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
            __('Type') => 'type',
            __('Comment') => 'comment',
        ];
    }

    public function fields(): array
    {
        return [
            'mac-address' => ['label' => __('MAC address'), 'rules' => ['nullable', 'required_without:form.address', Patterns::MAC]],
            'address' => ['label' => __('Address'), 'rules' => ['nullable', 'ipv4'], 'description' => __('A MAC address, an address, or both.')],
            'type' => ['label' => __('Type'), 'rules' => ['required', 'in:bypassed,blocked,regular'], 'type' => 'select', 'options' => ['bypassed' => __('Bypassed: no login needed'), 'blocked' => __('Blocked: no access'), 'regular' => __('Regular')]],
            'comment' => ['label' => __('Comment'), 'rules' => ['nullable', 'string', 'max:120'], 'placeholder' => __('Office printer')],
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
