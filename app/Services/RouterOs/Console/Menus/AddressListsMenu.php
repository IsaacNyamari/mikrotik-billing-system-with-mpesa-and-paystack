<?php

namespace App\Services\RouterOs\Console\Menus;

use App\Services\RouterOs\Console\ConsoleMenu;
use App\Services\RouterOs\Console\Patterns;

/**
 * /ip/firewall/address-list — named sets of addresses that firewall rules refer to.
 *
 * The safe way to block or allow somebody from a web page: the rules stay put and only their
 * membership changes. An entry can be given a timeout, so a "block for a day" undoes itself.
 */
class AddressListsMenu extends ConsoleMenu
{
    public function key(): string
    {
        return 'address-lists';
    }

    public function label(): string
    {
        return __('Address lists');
    }

    public function description(): string
    {
        return __('Named sets of addresses that firewall rules check. Give an entry a timeout and it removes itself.');
    }

    public function path(): string
    {
        return '/ip/firewall/address-list';
    }

    public function icon(): string
    {
        return 'list-bullet';
    }

    public function group(): string
    {
        return __('Firewall');
    }

    public function columns(): array
    {
        return [
            __('List') => 'list',
            __('Address') => 'address',
            __('Timeout') => 'timeout',
            __('Comment') => 'comment',
        ];
    }

    public function fields(): array
    {
        return [
            'list' => ['label' => __('List'), 'rules' => ['required', Patterns::NAME, 'max:64'], 'placeholder' => 'blocked'],
            'address' => ['label' => __('Address'), 'rules' => ['required', Patterns::ADDRESS], 'placeholder' => '10.10.10.57'],
            'timeout' => ['label' => __('Timeout'), 'rules' => ['nullable', Patterns::DURATION], 'placeholder' => '1d', 'description' => __('Optional. Removed automatically after this long.'), 'create_only' => true],
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
