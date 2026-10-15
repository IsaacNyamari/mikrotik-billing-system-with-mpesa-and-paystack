<?php

namespace App\Services\RouterOs\Console\Menus;

use App\Models\Router;
use App\Services\RouterOs\Console\ConsoleMenu;
use App\Services\RouterOs\Console\Patterns;
use App\Services\RouterOs\Console\PlatformRecords;

/**
 * /ip/hotspot/user — HotSpot logins.
 *
 * Packages sold through the portal are the platform's: it extends them after outages, expires
 * them on time and reports their earnings, and an edit here would put the device and the books
 * out of step. Logins made by hand — staff, a free hour for a complaint, a long-term guest —
 * are the ISP's, and are made and managed here.
 */
class HotspotUsersMenu extends ConsoleMenu
{
    public function __construct(protected PlatformRecords $records) {}

    public function key(): string
    {
        return 'hotspot-users';
    }

    public function label(): string
    {
        return __('HotSpot users');
    }

    public function description(): string
    {
        return __('HotSpot logins. Packages sold through the portal are managed by the platform and expire on their own; logins you add here are yours to manage.');
    }

    public function path(): string
    {
        return '/ip/hotspot/user';
    }

    public function icon(): string
    {
        return 'users';
    }

    public function group(): string
    {
        return __('HotSpot');
    }

    public function columns(): array
    {
        return [
            __('Name') => 'name',
            __('Profile') => 'profile',
            __('Uptime used') => 'uptime',
            __('Time limit') => 'limit-uptime',
            __('MAC address') => 'mac-address',
            __('Comment') => 'comment',
        ];
    }

    public function fields(): array
    {
        return [
            'name' => ['label' => __('Username'), 'rules' => ['required', Patterns::NAME, 'max:64']],
            'password' => ['label' => __('Password'), 'rules' => ['nullable', 'string', 'max:64'], 'type' => 'password', 'description' => __('May be empty for a login bound to a MAC address.')],
            'profile' => ['label' => __('Profile'), 'rules' => ['required', Patterns::NAME, 'max:64'], 'placeholder' => 'default', 'description' => __('A name from HotSpot profiles.')],
            'server' => ['label' => __('Server'), 'rules' => ['nullable', 'regex:/^(all|[A-Za-z0-9._\-]+)$/'], 'placeholder' => 'all'],
            'limit-uptime' => ['label' => __('Time limit'), 'rules' => ['nullable', Patterns::DURATION], 'placeholder' => '1h', 'description' => __('Online time allowed in total. Empty for no limit.')],
            'limit-bytes-total' => ['label' => __('Data limit (bytes)'), 'rules' => ['nullable', 'integer', 'min:0'], 'placeholder' => '1073741824'],
            'mac-address' => ['label' => __('Lock to MAC address'), 'rules' => ['nullable', Patterns::MAC]],
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

    public function protects(array $row, Router $router): ?string
    {
        $name = (string) ($row['name'] ?? '');

        // The trial user RouterOS keeps for its own trial feature.
        if (str_starts_with($name, 'default-trial')) {
            return __('A RouterOS built-in user.');
        }

        if ($this->records->soldHotspotUser($router, $name)) {
            return __('A package sold through the portal. The platform expires it on time and extends it after outages.');
        }

        return parent::protects($row, $router);
    }
}
