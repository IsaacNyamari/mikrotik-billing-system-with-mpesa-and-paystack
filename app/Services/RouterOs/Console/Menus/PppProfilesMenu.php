<?php

namespace App\Services\RouterOs\Console\Menus;

use App\Models\Router;
use App\Services\RouterOs\Console\ConsoleMenu;
use App\Services\RouterOs\Console\Patterns;
use App\Services\RouterOs\Console\PlatformRecords;

/**
 * /ppp/profile — what a PPPoE line gets: its speed, its addresses, its DNS.
 *
 * Profiles pushed from a plan are shown here and left to the plan. Editing the copy on the
 * router would last until the next sync and then silently revert.
 */
class PppProfilesMenu extends ConsoleMenu
{
    public function __construct(protected PlatformRecords $records) {}

    public function key(): string
    {
        return 'ppp-profiles';
    }

    public function label(): string
    {
        return __('PPP profiles');
    }

    public function description(): string
    {
        return __('Speed, addresses and DNS for PPPoE lines. Profiles made from a plan are changed by editing the plan.');
    }

    public function path(): string
    {
        return '/ppp/profile';
    }

    public function icon(): string
    {
        return 'rectangle-stack';
    }

    public function group(): string
    {
        return __('PPP');
    }

    public function columns(): array
    {
        return [
            __('Name') => 'name',
            __('Rate limit (up/down)') => 'rate-limit',
            __('Local address') => 'local-address',
            __('Remote address') => 'remote-address',
            __('DNS') => 'dns-server',
            __('Only one') => 'only-one',
            __('Comment') => 'comment',
        ];
    }

    public function fields(): array
    {
        return [
            'name' => ['label' => __('Name'), 'rules' => ['required', Patterns::NAME, 'max:64'], 'placeholder' => 'home-10m'],
            'rate-limit' => ['label' => __('Rate limit'), 'rules' => ['nullable', Patterns::RATE, 'max:64'], 'placeholder' => '5M/10M', 'description' => __('Upload/download. Empty for no limit.'), 'clearable' => true],
            'local-address' => ['label' => __('Local address'), 'rules' => ['nullable', 'max:64'], 'placeholder' => '10.20.0.1', 'description' => __('The router\'s side of each line: an address or a pool name.')],
            'remote-address' => ['label' => __('Remote address'), 'rules' => ['nullable', 'max:64'], 'placeholder' => 'pppoe-pool', 'description' => __('What the subscriber is given: an address or a pool name.')],
            'dns-server' => ['label' => __('DNS servers'), 'rules' => ['nullable', 'regex:/^[0-9.,]+$/', 'max:64'], 'placeholder' => '1.1.1.1,8.8.8.8'],
            'only-one' => ['label' => __('One session per login'), 'rules' => ['nullable', 'in:yes,no,default'], 'type' => 'select', 'options' => ['default' => __('Default'), 'yes' => __('Yes'), 'no' => __('No')]],
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

        // RouterOS ships these and refuses to remove them.
        if (in_array($name, ['default', 'default-encryption'], true) && ($row['default'] ?? 'true') !== 'false') {
            return __('A RouterOS built-in profile.');
        }

        if (($plan = $this->records->planProfile($router, $name)) !== null) {
            return __('Made from the plan ":plan". Edit the plan and it is pushed here.', ['plan' => $plan]);
        }

        return parent::protects($row, $router);
    }
}
