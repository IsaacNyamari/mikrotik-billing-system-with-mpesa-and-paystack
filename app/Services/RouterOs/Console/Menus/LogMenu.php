<?php

namespace App\Services\RouterOs\Console\Menus;

use App\Services\RouterOs\Console\ConsoleMenu;

/**
 * /log — what the router has been saying. Read-only, newest first.
 */
class LogMenu extends ConsoleMenu
{
    /**
     * A busy HotSpot logs every login. The device keeps its own short memory anyway; this is
     * about not shipping thousands of rows to a browser to show the screenful anyone reads.
     */
    public const LIMIT = 300;

    public function key(): string
    {
        return 'log';
    }

    public function label(): string
    {
        return __('Log');
    }

    public function description(): string
    {
        return __('The router\'s own log, newest first. It is kept in the router\'s memory and is cleared when it reboots.');
    }

    public function path(): string
    {
        return '/log';
    }

    public function icon(): string
    {
        return 'document-text';
    }

    public function group(): string
    {
        return __('System');
    }

    public function columns(): array
    {
        return [
            __('Time') => 'time',
            __('Topics') => 'topics',
            __('Message') => 'message',
        ];
    }

    public function present(array $rows): array
    {
        return array_slice(array_reverse($rows), 0, self::LIMIT);
    }
}
