<?php

namespace App\Services\RouterOs;

use App\Services\RouterOs\Contracts\RouterOsConnection;
use App\Services\RouterOs\Exceptions\RouterOsCommandException;
use RouterOS\Client;
use RouterOS\Interfaces\QueryInterface;
use RouterOS\Query;

/**
 * Talks to a real MikroTik over the RouterOS API.
 *
 * This is the only class in the application that knows the vendor library exists.
 */
class LiveRouterOsConnection implements RouterOsConnection
{
    public function __construct(protected Client $client) {}

    /**
     * @param  array<string, string|int|bool>  $where
     * @return array<int, array<string, string>>
     */
    public function get(string $path, array $where = []): array
    {
        $query = new Query($this->normalise($path).'/print');

        foreach ($where as $key => $value) {
            $query->where($key, $this->stringify($value));
        }

        return $this->rows($this->run($query, $path));
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
        $query = new Query($this->normalise($path).'/add');

        foreach ($attributes as $key => $value) {
            $query->equal($key, $this->stringify($value));
        }

        $response = $this->run($query, $path);

        // RouterOS answers an add with "=ret=*1A", which the library files under "after".
        return (string) ($response['after']['ret'] ?? '');
    }

    /**
     * @param  array<string, string|int|bool>  $attributes
     */
    public function update(string $path, string $id, array $attributes): void
    {
        $query = (new Query($this->normalise($path).'/set'))->equal('.id', $id);

        foreach ($attributes as $key => $value) {
            $query->equal($key, $this->stringify($value));
        }

        $this->run($query, $path);
    }

    public function remove(string $path, string $id): void
    {
        $this->run(
            (new Query($this->normalise($path).'/remove'))->equal('.id', $id),
            $path,
        );
    }

    /**
     * @param  array<string, string|int|bool>  $arguments
     * @return array<int, array<string, string>>
     */
    public function execute(string $command, array $arguments = []): array
    {
        $query = new Query($this->normalise($command));

        foreach ($arguments as $key => $value) {
            $query->equal($key, $this->stringify($value));
        }

        return $this->rows($this->run($query, $command));
    }

    public function disconnect(): void
    {
        // The vendor client closes its socket when it is garbage collected; there is no
        // explicit close to call. Dropping the reference is the whole of it.
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function run(QueryInterface $query, string $context): array
    {
        /** @var array<array-key, mixed> $response */
        $response = $this->client->query($query)->read();

        // A refused command comes back as "!trap" with a message rather than as an exception.
        if (isset($response['after']['message'])) {
            throw RouterOsCommandException::trap($context, (string) $response['after']['message']);
        }

        return $response;
    }

    /**
     * Keep only the numbered result rows, dropping the library's trailing "after" entry.
     *
     * @param  array<array-key, mixed>  $response
     * @return array<int, array<string, string>>
     */
    protected function rows(array $response): array
    {
        $rows = [];

        foreach ($response as $key => $row) {
            if (is_int($key) && is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * RouterOS menu paths have no trailing slash.
     */
    protected function normalise(string $path): string
    {
        return '/'.trim($path, '/');
    }

    /**
     * RouterOS speaks strings; booleans go over the wire as "yes"/"no".
     */
    protected function stringify(string|int|bool $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'yes' : 'no',
            default => (string) $value,
        };
    }
}
