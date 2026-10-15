<?php

namespace App\Services\RouterOs\Console\Menus;

use App\Services\RouterOs\Console\ConsoleMenu;

/**
 * /ip/firewall/filter — what the router lets through.
 *
 * Shown, labelled, switched and removed here, but not added to. Filter rules are read top to
 * bottom and the first match wins, so a rule's position is its meaning — and a rule appended
 * after the platform's final drop would never match anything, while one placed above the API
 * guard could open the router to the internet. Blocking someone is done with an address list
 * instead, which the existing rules already consult.
 */
class FirewallFilterMenu extends ConsoleMenu
{
    public function key(): string
    {
        return 'firewall-filter';
    }

    public function label(): string
    {
        return __('Firewall rules');
    }

    public function description(): string
    {
        return __('What the router lets through, in the order it checks. The platform\'s protection rules are kept as they are; to block an address, use Address lists.');
    }

    public function path(): string
    {
        return '/ip/firewall/filter';
    }

    public function icon(): string
    {
        return 'shield-check';
    }

    public function group(): string
    {
        return __('Firewall');
    }

    public function columns(): array
    {
        return [
            __('Chain') => 'chain',
            __('Action') => 'action',
            __('Protocol') => 'protocol',
            __('Src. address') => 'src-address',
            __('Dst. port') => 'dst-port',
            __('In interface') => 'in-interface',
            __('Comment') => 'comment',
        ];
    }

    public function fields(): array
    {
        return [
            'comment' => ['label' => __('Comment'), 'rules' => ['nullable', 'string', 'max:120']],
        ];
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
