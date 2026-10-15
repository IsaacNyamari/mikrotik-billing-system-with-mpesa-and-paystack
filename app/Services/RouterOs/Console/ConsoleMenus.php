<?php

namespace App\Services\RouterOs\Console;

use App\Services\RouterOs\Console\Menus\DhcpLeasesMenu;
use App\Services\RouterOs\Console\Menus\InterfacesMenu;
use App\Services\RouterOs\Console\Menus\LogMenu;
use App\Services\RouterOs\Console\Menus\SimpleQueuesMenu;

/**
 * Every screen the router console offers.
 *
 * Adding one of Winbox's menus to the dashboard is a class under Menus/ and a line here.
 */
class ConsoleMenus
{
    /**
     * @var array<int, class-string<ConsoleMenu>>
     */
    protected const MENUS = [
        InterfacesMenu::class,
        DhcpLeasesMenu::class,
        SimpleQueuesMenu::class,
        LogMenu::class,
    ];

    /**
     * @return array<string, ConsoleMenu>
     */
    public function all(): array
    {
        $menus = [];

        foreach (self::MENUS as $class) {
            $menu = app($class);
            $menus[$menu->key()] = $menu;
        }

        return $menus;
    }

    /**
     * Menus grouped the way the console's navigation shows them.
     *
     * @return array<string, array<string, ConsoleMenu>>
     */
    public function grouped(): array
    {
        $groups = [];

        foreach ($this->all() as $key => $menu) {
            $groups[$menu->group()][$key] = $menu;
        }

        return $groups;
    }

    public function find(string $key): ?ConsoleMenu
    {
        return $this->all()[$key] ?? null;
    }

    public function first(): ConsoleMenu
    {
        $menus = $this->all();

        return reset($menus) ?: app(InterfacesMenu::class);
    }
}
