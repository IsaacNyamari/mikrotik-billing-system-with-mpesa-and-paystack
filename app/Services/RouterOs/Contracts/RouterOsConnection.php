<?php

namespace App\Services\RouterOs\Contracts;

/**
 * A live conversation with one RouterOS device.
 *
 * The platform never talks to the vendor library directly. Everything goes through this
 * interface, which keeps the wire client swappable and lets tests run against a fake device
 * instead of real hardware.
 *
 * Paths are RouterOS menu paths without a trailing action, e.g. "/ip/hotspot/user".
 */
interface RouterOsConnection
{
    /**
     * Read rows from a menu, optionally filtered.
     *
     * Filters are equality matches on RouterOS property names, e.g. ['name' => 'GDGDAHXS'].
     *
     * @param  array<string, string|int|bool>  $where
     * @return array<int, array<string, string>>
     */
    public function get(string $path, array $where = []): array;

    /**
     * Read the first matching row, or null when the menu has none.
     *
     * @param  array<string, string|int|bool>  $where
     * @return array<string, string>|null
     */
    public function first(string $path, array $where = []): ?array;

    /**
     * Add a row to a menu and return its RouterOS internal id (".id", e.g. "*1A").
     *
     * @param  array<string, string|int|bool>  $attributes
     */
    public function add(string $path, array $attributes): string;

    /**
     * Update the row with the given RouterOS internal id.
     *
     * @param  array<string, string|int|bool>  $attributes
     */
    public function update(string $path, string $id, array $attributes): void;

    /**
     * Remove the row with the given RouterOS internal id.
     */
    public function remove(string $path, string $id): void;

    /**
     * Run a command that is neither a read nor a plain row edit, e.g. "/ip/hotspot/active/remove".
     *
     * @param  array<string, string|int|bool>  $arguments
     * @return array<int, array<string, string>>
     */
    public function execute(string $command, array $arguments = []): array;

    /**
     * Close the connection. Calling this more than once is safe.
     */
    public function disconnect(): void;
}
