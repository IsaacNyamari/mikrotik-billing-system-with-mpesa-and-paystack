<?php

namespace App\Services\RouterOs\Console\Menus;

use App\Services\RouterOs\Console\ConsoleMenu;

/**
 * /ip/dhcp-server/lease — who has been handed an address, and the addresses kept for devices
 * that must always have the same one: a printer, an access point, the till.
 */
class DhcpLeasesMenu extends ConsoleMenu
{
    public function key(): string
    {
        return 'dhcp-leases';
    }

    public function label(): string
    {
        return __('DHCP leases');
    }

    public function description(): string
    {
        return __('Addresses the router has handed out. Make one static to keep that address for that device.');
    }

    public function path(): string
    {
        return '/ip/dhcp-server/lease';
    }

    public function icon(): string
    {
        return 'computer-desktop';
    }

    public function group(): string
    {
        return __('IP');
    }

    public function columns(): array
    {
        return [
            __('Address') => 'address',
            __('MAC address') => 'mac-address',
            __('Host name') => 'host-name',
            __('Server') => 'server',
            __('Status') => 'status',
            __('Dynamic') => 'dynamic',
            __('Comment') => 'comment',
        ];
    }

    public function fields(): array
    {
        return [
            'address' => [
                'label' => __('Address'),
                'rules' => ['required', 'ipv4'],
                'placeholder' => '10.10.10.20',
            ],
            'mac-address' => [
                'label' => __('MAC address'),
                'rules' => ['required', 'regex:/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/'],
                'placeholder' => 'AA:BB:CC:DD:EE:FF',
                'create_only' => true,
            ],
            'server' => [
                'label' => __('DHCP server'),
                'rules' => ['nullable', 'regex:/^[A-Za-z0-9._-]+$/', 'max:64'],
                'placeholder' => 'dhcp-billing-hotspot',
                'description' => __('Leave empty to apply on every DHCP server.'),
                'create_only' => true,
            ],
            'comment' => [
                'label' => __('Comment'),
                'rules' => ['nullable', 'string', 'max:120'],
            ],
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

    /**
     * Most leases are dynamic — that is what DHCP hands out — and making one static is the
     * reason to be on this screen at all.
     */
    public function protectsDynamic(): bool
    {
        return false;
    }

    public function rowCommands(): array
    {
        return [
            'make-static' => [
                'label' => __('Make static'),
                'command' => '/ip/dhcp-server/lease/make-static',
                'icon' => 'lock-closed',
            ],
        ];
    }

    public function commandApplies(string $command, array $row): bool
    {
        // RouterOS refuses make-static on a lease that already is one.
        return $command !== 'make-static' || ($row['dynamic'] ?? 'false') === 'true';
    }
}
