<?php

namespace App\Services\RouterOs\Console;

use App\Services\RouterOs\Console\Menus\AddressListsMenu;
use App\Services\RouterOs\Console\Menus\DhcpLeasesMenu;
use App\Services\RouterOs\Console\Menus\DnsStaticMenu;
use App\Services\RouterOs\Console\Menus\FirewallFilterMenu;
use App\Services\RouterOs\Console\Menus\FirewallNatMenu;
use App\Services\RouterOs\Console\Menus\HotspotActiveMenu;
use App\Services\RouterOs\Console\Menus\HotspotBindingsMenu;
use App\Services\RouterOs\Console\Menus\HotspotHostsMenu;
use App\Services\RouterOs\Console\Menus\HotspotProfilesMenu;
use App\Services\RouterOs\Console\Menus\HotspotUsersMenu;
use App\Services\RouterOs\Console\Menus\IdentityMenu;
use App\Services\RouterOs\Console\Menus\InterfacesMenu;
use App\Services\RouterOs\Console\Menus\IpAddressesMenu;
use App\Services\RouterOs\Console\Menus\IpPoolsMenu;
use App\Services\RouterOs\Console\Menus\LogMenu;
use App\Services\RouterOs\Console\Menus\PppActiveMenu;
use App\Services\RouterOs\Console\Menus\PppProfilesMenu;
use App\Services\RouterOs\Console\Menus\PppSecretsMenu;
use App\Services\RouterOs\Console\Menus\RoutesMenu;
use App\Services\RouterOs\Console\Menus\SimpleQueuesMenu;
use App\Services\RouterOs\Console\Menus\WalledGardenMenu;

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
        HotspotActiveMenu::class,
        HotspotUsersMenu::class,
        HotspotProfilesMenu::class,
        HotspotHostsMenu::class,
        HotspotBindingsMenu::class,
        WalledGardenMenu::class,
        PppActiveMenu::class,
        PppSecretsMenu::class,
        PppProfilesMenu::class,
        InterfacesMenu::class,
        IpAddressesMenu::class,
        IpPoolsMenu::class,
        DhcpLeasesMenu::class,
        RoutesMenu::class,
        DnsStaticMenu::class,
        FirewallNatMenu::class,
        FirewallFilterMenu::class,
        AddressListsMenu::class,
        SimpleQueuesMenu::class,
        IdentityMenu::class,
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
