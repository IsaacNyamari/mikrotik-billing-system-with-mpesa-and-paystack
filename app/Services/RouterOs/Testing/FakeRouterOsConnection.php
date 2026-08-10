<?php

namespace App\Services\RouterOs\Testing;

use App\Services\RouterOs\Contracts\RouterOsConnection;
use App\Services\RouterOs\Exceptions\RouterOsCommandException;

/**
 * An in-memory MikroTik.
 *
 * Behaves like a real device for the operations the platform performs: rows are stored per
 * menu path, get an ".id", and can be filtered, updated and removed. Tests assert against the
 * resulting state rather than against a list of calls, so they describe what the router ends
 * up holding rather than how we got there.
 */
class FakeRouterOsConnection implements RouterOsConnection
{
    /**
     * Stored rows, keyed by menu path.
     *
     * @var array<string, array<int, array<string, string>>>
     */
    protected array $menus = [];

    /**
     * Every command issued, for the rare test that cares about ordering.
     *
     * @var array<int, array{operation: string, path: string, payload: array<string, mixed>}>
     */
    public array $log = [];

    /**
     * Paths configured to refuse the next command, keyed by path.
     *
     * @var array<string, string>
     */
    protected array $traps = [];

    public bool $disconnected = false;

    protected int $nextId = 1;

    /**
     * @param  array<string, array<int, array<string, string>>>  $menus
     */
    public function __construct(array $menus = [])
    {
        foreach ($menus as $path => $rows) {
            foreach ($rows as $row) {
                $this->add($path, $row);
            }
        }

        $this->log = [];
    }

    /**
     * Make the next command against this path come back as a RouterOS "!trap".
     */
    public function trap(string $path, string $message): void
    {
        $this->traps[$this->normalise($path)] = $message;
    }

    /**
     * @param  array<string, string|int|bool>  $where
     * @return array<int, array<string, string>>
     */
    public function get(string $path, array $where = []): array
    {
        $path = $this->normalise($path);
        $this->guard($path);
        $this->log[] = ['operation' => 'get', 'path' => $path, 'payload' => $where];

        $rows = $this->menus[$path] ?? [];

        foreach ($where as $key => $value) {
            $expected = $this->stringify($value);
            $rows = array_values(array_filter(
                $rows,
                fn (array $row): bool => ($row[$key] ?? null) === $expected,
            ));
        }

        return $rows;
    }

    /**
     * @param  array<string, string|int|bool>  $where
     * @return array<string, string>|null
     */
    public function first(string $path, array $where = []): ?array
    {
        return $this->get($path, $where)[0] ?? null;
    }

    /**
     * @param  array<string, string|int|bool>  $attributes
     */
    public function add(string $path, array $attributes): string
    {
        $path = $this->normalise($path);
        $this->guard($path);
        $this->log[] = ['operation' => 'add', 'path' => $path, 'payload' => $attributes];

        $id = '*'.strtoupper(dechex($this->nextId++));

        $row = ['.id' => $id];

        foreach ($attributes as $key => $value) {
            $row[$key] = $this->stringify($value);
        }

        $this->menus[$path][] = $row;

        return $id;
    }

    /**
     * @param  array<string, string|int|bool>  $attributes
     */
    public function update(string $path, string $id, array $attributes): void
    {
        $path = $this->normalise($path);
        $this->guard($path);
        $this->log[] = ['operation' => 'update', 'path' => $path, 'payload' => ['.id' => $id] + $attributes];

        foreach ($this->menus[$path] ?? [] as $index => $row) {
            if (($row['.id'] ?? null) !== $id) {
                continue;
            }

            foreach ($attributes as $key => $value) {
                $this->menus[$path][$index][$key] = $this->stringify($value);
            }

            return;
        }

        throw RouterOsCommandException::trap($path, 'no such item');
    }

    public function remove(string $path, string $id): void
    {
        $path = $this->normalise($path);
        $this->guard($path);
        $this->log[] = ['operation' => 'remove', 'path' => $path, 'payload' => ['.id' => $id]];

        $before = count($this->menus[$path] ?? []);

        $this->menus[$path] = array_values(array_filter(
            $this->menus[$path] ?? [],
            fn (array $row): bool => ($row['.id'] ?? null) !== $id,
        ));

        if (count($this->menus[$path]) === $before) {
            throw RouterOsCommandException::trap($path, 'no such item');
        }
    }

    /**
     * @param  array<string, string|int|bool>  $arguments
     * @return array<int, array<string, string>>
     */
    public function execute(string $command, array $arguments = []): array
    {
        $command = $this->normalise($command);
        $this->guard($command);
        $this->log[] = ['operation' => 'execute', 'path' => $command, 'payload' => $arguments];

        return $this->menus[$command] ?? [];
    }

    public function disconnect(): void
    {
        $this->disconnected = true;
    }

    /**
     * Seed a menu directly, for readings the platform never writes — /system/resource and
     * friends.
     *
     * @param  array<int, array<string, string>>  $rows
     */
    public function seed(string $path, array $rows): void
    {
        $this->menus[$this->normalise($path)] = $rows;
    }

    /**
     * Everything currently stored at a path.
     *
     * @return array<int, array<string, string>>
     */
    public function rows(string $path): array
    {
        return $this->menus[$this->normalise($path)] ?? [];
    }

    protected function guard(string $path): void
    {
        if (! isset($this->traps[$path])) {
            return;
        }

        $message = $this->traps[$path];
        unset($this->traps[$path]);

        throw RouterOsCommandException::trap($path, $message);
    }

    protected function normalise(string $path): string
    {
        return '/'.trim($path, '/');
    }

    protected function stringify(string|int|bool $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'yes' : 'no',
            default => (string) $value,
        };
    }
}
