<?php

namespace App\Services\RouterOs\Console;

use App\Models\Router;
use App\Services\RouterOs\Contracts\RouterOsConnection;
use App\Services\RouterOs\Contracts\RouterOsConnector;
use App\Support\Audit\AuditLogger;
use Closure;

/**
 * Reads and changes a router's configuration on an operator's behalf.
 *
 * Every write the dashboard makes to a device comes through here, so three things hold for all
 * of them whichever screen asked:
 *
 *  - only the properties the menu offers are sent, whatever arrived from the browser;
 *  - a row the platform depends on is refused before anything reaches the device;
 *  - the change is written to the audit trail with what it was before.
 *
 * Authorization is the caller's job — the page knows who is asking, this does not.
 *
 * Single commands run inline rather than through the queue. Each is tens of bytes and the
 * operator is watching the table for the result; a queued "disable ether2" that lands
 * whenever a worker gets to it is a screen that lies about the device.
 */
class RouterConsole
{
    public function __construct(
        protected RouterOsConnector $connector,
        protected AuditLogger $audit,
    ) {}

    /**
     * Everything the menu holds on the device, shaped for display.
     *
     * @return array<int, array<string, string>>
     */
    public function rows(Router $router, ConsoleMenu $menu): array
    {
        return $this->using($router, fn (RouterOsConnection $connection): array => $menu->present(
            $connection->get($menu->path()),
        ));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function add(Router $router, ConsoleMenu $menu, array $values): string
    {
        if (! $menu->canAdd()) {
            throw new ConsoleRefusal(__(':menu cannot be added to from here.', ['menu' => $menu->label()]));
        }

        $payload = $menu->payload($values, creating: true);

        // Checked against what is about to exist, so a row cannot be created already carrying
        // a comment that marks it as the platform's — and then be beyond anyone's reach.
        if (($reason = $menu->protects($payload, $router)) !== null) {
            throw new ConsoleRefusal($reason);
        }

        return $this->using($router, function (RouterOsConnection $connection) use ($router, $menu, $payload): string {
            $id = $connection->add($menu->path(), $payload);

            $this->record($router, $menu, 'added', $payload, null, $payload);

            return $id;
        });
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(Router $router, ConsoleMenu $menu, string $id, array $values): void
    {
        if (! $menu->canEdit()) {
            throw new ConsoleRefusal(__(':menu cannot be edited from here.', ['menu' => $menu->label()]));
        }

        $payload = $menu->payload($values, creating: false);

        $this->using($router, function (RouterOsConnection $connection) use ($router, $menu, $id, $payload): void {
            $row = $this->guardedRow($connection, $router, $menu, $id);

            // The edited row must not end up protected either: writing a platform comment
            // onto an ordinary row would lock the ISP out of their own configuration.
            if (($reason = $menu->protects(array_merge($row, $payload), $router)) !== null) {
                throw new ConsoleRefusal($reason);
            }

            $connection->update($menu->path(), $id, $payload);

            $this->record($router, $menu, 'updated', $row, array_intersect_key($row, $payload), $payload);
        });
    }

    public function remove(Router $router, ConsoleMenu $menu, string $id): void
    {
        if (! $menu->canRemove()) {
            throw new ConsoleRefusal(__(':menu cannot be removed from here.', ['menu' => $menu->label()]));
        }

        $this->using($router, function (RouterOsConnection $connection) use ($router, $menu, $id): void {
            $row = $this->guardedRow($connection, $router, $menu, $id);

            $connection->remove($menu->path(), $id);

            $this->record($router, $menu, 'removed', $row, $this->withoutId($row), null);
        });
    }

    public function toggle(Router $router, ConsoleMenu $menu, string $id, bool $disable): void
    {
        if (! $menu->canToggle()) {
            throw new ConsoleRefusal(__(':menu cannot be enabled or disabled from here.', ['menu' => $menu->label()]));
        }

        $this->using($router, function (RouterOsConnection $connection) use ($router, $menu, $id, $disable): void {
            $row = $this->guardedRow($connection, $router, $menu, $id);

            $connection->update($menu->path(), $id, ['disabled' => $disable]);

            $this->record(
                $router,
                $menu,
                $disable ? 'disabled' : 'enabled',
                $row,
                ['disabled' => $row['disabled'] ?? null],
                ['disabled' => $disable ? 'true' : 'false'],
            );
        });
    }

    public function command(Router $router, ConsoleMenu $menu, string $id, string $command): void
    {
        $definition = $menu->rowCommands()[$command] ?? null;

        if ($definition === null) {
            throw new ConsoleRefusal(__('That is not something :menu can do.', ['menu' => $menu->label()]));
        }

        $this->using($router, function (RouterOsConnection $connection) use ($router, $menu, $id, $command, $definition): void {
            $row = $this->guardedRow($connection, $router, $menu, $id);

            if (! $menu->commandApplies($command, $row)) {
                throw new ConsoleRefusal(__(':action does not apply to this row.', ['action' => $definition['label']]));
            }

            $connection->execute($definition['command'], ['.id' => $id]);

            $this->record($router, $menu, $command, $row, $this->withoutId($row), null);
        });
    }

    /**
     * Read one row back from the device and refuse it if the platform depends on it.
     *
     * Read fresh rather than trusted from the page: what the browser drew may be minutes old,
     * and the row it names may since have been renamed, re-commented or removed.
     *
     * @return array<string, string>
     */
    protected function guardedRow(RouterOsConnection $connection, Router $router, ConsoleMenu $menu, string $id): array
    {
        $row = $connection->first($menu->path(), ['.id' => $id]);

        if ($row === null) {
            throw new ConsoleRefusal(__('That row is no longer on the router. Refresh to see what is.'));
        }

        if (($reason = $menu->protects($row, $router)) !== null) {
            throw new ConsoleRefusal($reason);
        }

        return $row;
    }

    /**
     * @template T
     *
     * @param  Closure(RouterOsConnection): T  $callback
     * @return T
     */
    protected function using(Router $router, Closure $callback): mixed
    {
        $connection = $this->connector->connect($router);

        try {
            return $callback($connection);
        } finally {
            $connection->disconnect();
        }
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    protected function record(Router $router, ConsoleMenu $menu, string $action, array $row, ?array $old, ?array $new): void
    {
        $this->audit->record(
            event: 'router.console.'.$action,
            auditable: $router,
            description: sprintf(
                '%s: %s %s on %s',
                $menu->label(),
                ucfirst($action),
                $this->describe($row),
                $router->name,
            ),
            oldValues: $old === null ? null : ['menu' => $menu->path()] + $old,
            newValues: $new === null ? null : ['menu' => $menu->path()] + $new,
        );
    }

    /**
     * What an operator would call this row, for the audit trail.
     *
     * @param  array<string, string>  $row
     */
    protected function describe(array $row): string
    {
        foreach (['name', 'address', 'mac-address', 'target'] as $property) {
            if (($row[$property] ?? '') !== '') {
                return $row[$property];
            }
        }

        return $row['.id'] ?? 'row';
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, string>
     */
    protected function withoutId(array $row): array
    {
        unset($row['.id']);

        return $row;
    }
}
