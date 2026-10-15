<?php

namespace App\Services\RouterOs\Console\Menus;

use App\Services\RouterOs\Console\ConsoleMenu;
use App\Services\RouterOs\Console\Patterns;

/**
 * /system/identity — the router's own name, as Winbox and the log show it.
 *
 * A single-row menu with no ids of its own: RouterOS addresses it as a whole, so the console
 * writes it with `set` and no `.id`, which is what RouterConsole does for a row it cannot name.
 */
class IdentityMenu extends ConsoleMenu
{
    public function key(): string
    {
        return 'identity';
    }

    public function label(): string
    {
        return __('Identity');
    }

    public function description(): string
    {
        return __('The router\'s name, as it appears in Winbox, in its log and in the neighbour list.');
    }

    public function path(): string
    {
        return '/system/identity';
    }

    public function icon(): string
    {
        return 'identification';
    }

    public function group(): string
    {
        return __('System');
    }

    public function columns(): array
    {
        return [
            __('Name') => 'name',
        ];
    }

    public function fields(): array
    {
        return [
            'name' => ['label' => __('Name'), 'rules' => ['required', Patterns::NAME, 'max:64']],
        ];
    }

    public function isSingleton(): bool
    {
        return true;
    }
}
