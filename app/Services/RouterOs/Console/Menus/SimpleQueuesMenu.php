<?php

namespace App\Services\RouterOs\Console\Menus;

use App\Models\Router;
use App\Services\RouterOs\Console\ConsoleMenu;

/**
 * /queue/simple — speed limits on an address or a range of them.
 *
 * Plans already carry their own speed through the HotSpot and PPP profiles. This is for what
 * falls outside a plan: capping the office network, guaranteeing the CCTV recorder its share.
 */
class SimpleQueuesMenu extends ConsoleMenu
{
    public function key(): string
    {
        return 'queues';
    }

    public function label(): string
    {
        return __('Simple queues');
    }

    public function description(): string
    {
        return __('Speed limits on an address or network. Queues the HotSpot creates for logged-in customers come and go with them and are shown read-only.');
    }

    public function path(): string
    {
        return '/queue/simple';
    }

    public function icon(): string
    {
        return 'adjustments-horizontal';
    }

    public function group(): string
    {
        return __('Queues');
    }

    public function columns(): array
    {
        return [
            __('Name') => 'name',
            __('Target') => 'target',
            __('Max limit (up/down)') => 'max-limit',
            __('Comment') => 'comment',
        ];
    }

    public function fields(): array
    {
        return [
            'name' => [
                'label' => __('Name'),
                'rules' => ['required', 'regex:/^[A-Za-z0-9][A-Za-z0-9._ -]*$/', 'max:64'],
                'placeholder' => __('Office'),
            ],
            'target' => [
                'label' => __('Target'),
                'rules' => ['required', 'regex:/^(\d{1,3}(\.\d{1,3}){3}(\/\d{1,2})?)(,\d{1,3}(\.\d{1,3}){3}(\/\d{1,2})?)*$/'],
                'placeholder' => '10.10.10.20/32',
                'description' => __('An address or network, or several separated by commas.'),
            ],
            'max-limit' => [
                'label' => __('Max limit'),
                // Written upload/download, as RouterOS reads it from the client's side here —
                // unlike a profile's rate-limit, a simple queue's target is the client.
                'rules' => ['required', 'regex:/^\d+[kKmMgG]?\/\d+[kKmMgG]?$/'],
                'placeholder' => '5M/10M',
                'description' => __('Upload/download, e.g. 5M/10M.'),
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

    public function protects(array $row, Router $router): ?string
    {
        // The HotSpot builds one of these per logged-in customer and removes it when they
        // leave. RouterOS refuses edits to them anyway; saying why beats relaying its refusal.
        if (($row['dynamic'] ?? 'false') === 'true') {
            return __('Created by the HotSpot for a logged-in customer. It goes when they do.');
        }

        return parent::protects($row, $router);
    }
}
