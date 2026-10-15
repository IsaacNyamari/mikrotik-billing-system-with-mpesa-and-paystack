<?php

namespace App\Services\RouterOs\Console\Menus;

use App\Models\Router;
use App\Services\RouterOs\Console\ConsoleMenu;
use App\Services\RouterOs\Console\Patterns;
use App\Services\RouterOs\Console\PlatformRecords;

/**
 * /ppp/secret — PPPoE logins.
 *
 * Lines that belong to a customer's service account are managed from that account: suspending
 * one there both cuts the line and stops the billing, which disabling it here would not.
 * Everything else — a staff line, a test login — is the ISP's to manage here.
 */
class PppSecretsMenu extends ConsoleMenu
{
    public function __construct(protected PlatformRecords $records) {}

    public function key(): string
    {
        return 'ppp-secrets';
    }

    public function label(): string
    {
        return __('PPP users');
    }

    public function description(): string
    {
        return __('PPPoE logins. Lines that belong to a customer account are managed from that account in Customers.');
    }

    public function path(): string
    {
        return '/ppp/secret';
    }

    public function icon(): string
    {
        return 'user-circle';
    }

    public function group(): string
    {
        return __('PPP');
    }

    public function columns(): array
    {
        return [
            __('Name') => 'name',
            __('Service') => 'service',
            __('Profile') => 'profile',
            __('Remote address') => 'remote-address',
            __('Last logged out') => 'last-logged-out',
            __('Comment') => 'comment',
        ];
    }

    public function fields(): array
    {
        return [
            'name' => ['label' => __('Username'), 'rules' => ['required', Patterns::NAME, 'max:64']],
            'password' => ['label' => __('Password'), 'rules' => ['required', 'string', 'min:4', 'max:64'], 'type' => 'password'],
            'service' => ['label' => __('Service'), 'rules' => ['required', 'in:any,pppoe,pptp,l2tp,ovpn,sstp'], 'type' => 'select', 'options' => ['pppoe' => 'PPPoE', 'any' => __('Any'), 'pptp' => 'PPTP', 'l2tp' => 'L2TP', 'ovpn' => 'OpenVPN', 'sstp' => 'SSTP']],
            'profile' => ['label' => __('Profile'), 'rules' => ['required', Patterns::NAME, 'max:64'], 'placeholder' => 'default', 'description' => __('A name from PPP profiles.')],
            'remote-address' => ['label' => __('Fixed address'), 'rules' => ['nullable', 'ipv4'], 'description' => __('Optional. Leave empty to take one from the profile\'s pool.')],
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
        if (($account = $this->records->pppAccount($router, (string) ($row['name'] ?? ''))) !== null) {
            return __('Belongs to service account :account. Suspend, change or end it there, so billing follows.', ['account' => $account]);
        }

        return parent::protects($row, $router);
    }
}
