<?php

namespace App\Services\RouterOs\Console\Menus;

use App\Models\Router;
use App\Services\RouterOs\Console\ConsoleMenu;
use App\Services\RouterOs\Console\Patterns;
use App\Services\RouterOs\Console\PlatformRecords;

/**
 * /ip/hotspot/user/profile — what everyone on a HotSpot package shares: speed, devices per
 * login, timeouts.
 *
 * Profiles made from a plan belong to the plan, for the same reason as PPP profiles. One made
 * here is for logins created by hand, or a plan the ISP has not modelled on the platform.
 */
class HotspotProfilesMenu extends ConsoleMenu
{
    public function __construct(protected PlatformRecords $records) {}

    public function key(): string
    {
        return 'hotspot-profiles';
    }

    public function label(): string
    {
        return __('HotSpot profiles');
    }

    public function description(): string
    {
        return __('Speed, devices per login and timeouts for HotSpot users. Profiles made from a plan are changed by editing the plan.');
    }

    public function path(): string
    {
        return '/ip/hotspot/user/profile';
    }

    public function icon(): string
    {
        return 'rectangle-stack';
    }

    public function group(): string
    {
        return __('HotSpot');
    }

    public function columns(): array
    {
        return [
            __('Name') => 'name',
            __('Rate limit (up/down)') => 'rate-limit',
            __('Shared users') => 'shared-users',
            __('Session timeout') => 'session-timeout',
            __('Idle timeout') => 'idle-timeout',
            __('Comment') => 'comment',
        ];
    }

    public function fields(): array
    {
        return [
            'name' => ['label' => __('Name'), 'rules' => ['required', Patterns::NAME, 'max:64'], 'placeholder' => 'staff'],
            'rate-limit' => ['label' => __('Rate limit'), 'rules' => ['nullable', Patterns::RATE, 'max:64'], 'placeholder' => '2M/5M', 'description' => __('Upload/download. Empty for no limit.'), 'clearable' => true],
            'shared-users' => ['label' => __('Devices per login'), 'rules' => ['nullable', 'integer', 'min:1', 'max:1000'], 'placeholder' => '1'],
            'session-timeout' => ['label' => __('Session timeout'), 'rules' => ['nullable', Patterns::DURATION], 'placeholder' => '1h', 'description' => __('Logged out after this long, then free to log in again.')],
            'idle-timeout' => ['label' => __('Idle timeout'), 'rules' => ['nullable', Patterns::DURATION], 'placeholder' => '10m'],
            'keepalive-timeout' => ['label' => __('Keepalive timeout'), 'rules' => ['nullable', Patterns::DURATION], 'placeholder' => '2m', 'description' => __('How long a device can drop off the wifi before its session ends.')],
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

    public function protects(array $row, Router $router): ?string
    {
        $name = (string) ($row['name'] ?? '');

        if ($name === 'default' && ($row['default'] ?? 'true') !== 'false') {
            return __('A RouterOS built-in profile.');
        }

        if (($plan = $this->records->planProfile($router, $name)) !== null) {
            return __('Made from the plan ":plan". Edit the plan and it is pushed here.', ['plan' => $plan]);
        }

        return parent::protects($row, $router);
    }
}
