<?php

namespace App\Services\RouterOs;

use App\Services\RouterOs\Contracts\RouterOsConnection;
use App\Services\RouterOs\Exceptions\RouterOsCommandException;
use Throwable;

/**
 * A connection that survives being disconnected, so the next caller can keep using it.
 *
 * Callers close a connection when they have finished with it, and that is the right thing for
 * them to do — they cannot know whether anyone else is about to want the same router. Here the
 * close is recorded and nothing else, and {@see PooledRouterOsConnector} hands the same socket
 * to whoever comes next.
 *
 * The one thing this has to get right is knowing when the socket has actually died. A device
 * refusing a command answers with a `!trap` and the connection is fine — that is the router
 * disagreeing with us, not the network failing. Anything else means the conversation itself
 * broke, and the connection is marked unusable so the pool opens a fresh one rather than
 * handing on something that will fail again.
 */
class PooledRouterOsConnection implements RouterOsConnection
{
    protected bool $broken = false;

    public function __construct(protected RouterOsConnection $connection) {}

    /**
     * @param  array<string, string|int|bool>  $where
     * @return array<int, array<string, string>>
     */
    public function get(string $path, array $where = []): array
    {
        return $this->guard(fn (): array => $this->connection->get($path, $where));
    }

    /**
     * @param  array<string, string|int|bool>  $where
     * @return array<string, string>|null
     */
    public function first(string $path, array $where = []): ?array
    {
        return $this->guard(fn (): ?array => $this->connection->first($path, $where));
    }

    /**
     * @param  array<string, string|int|bool>  $attributes
     */
    public function add(string $path, array $attributes): string
    {
        return $this->guard(fn (): string => $this->connection->add($path, $attributes));
    }

    /**
     * @param  array<string, string|int|bool>  $attributes
     */
    public function update(string $path, string $id, array $attributes): void
    {
        $this->guard(function () use ($path, $id, $attributes): void {
            $this->connection->update($path, $id, $attributes);
        });
    }

    public function remove(string $path, string $id): void
    {
        $this->guard(function () use ($path, $id): void {
            $this->connection->remove($path, $id);
        });
    }

    /**
     * @param  array<string, string|int|bool>  $arguments
     * @return array<int, array<string, string>>
     */
    public function execute(string $command, array $arguments = []): array
    {
        return $this->guard(fn (): array => $this->connection->execute($command, $arguments));
    }

    /**
     * Deliberately does nothing.
     *
     * The caller has finished; the pool has not. Closing here would throw away a socket the
     * next service in the same request is about to reopen at the cost of another round trip.
     */
    public function disconnect(): void {}

    /**
     * Whether this connection is still worth handing to somebody.
     */
    public function isUsable(): bool
    {
        return ! $this->broken;
    }

    /**
     * Actually close, when the pool itself is finished with it.
     */
    public function close(): void
    {
        $this->broken = true;

        $this->connection->disconnect();
    }

    /**
     * Run a command, noticing whether the failure was the router's or the network's.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $operation
     * @return TReturn
     */
    protected function guard(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (RouterOsCommandException $e) {
            // The device answered and said no. The socket is healthy and the next caller may
            // use it.
            throw $e;
        } catch (Throwable $e) {
            $this->broken = true;

            throw $e;
        }
    }
}
