<?php

namespace Tests\Feature\Routers;

use App\Models\Router;
use App\Models\Tenant;
use App\Services\RouterOs\Contracts\RouterOsConnection;
use App\Services\RouterOs\Contracts\RouterOsConnector;
use App\Services\RouterOs\Exceptions\RouterOsCommandException;
use App\Services\RouterOs\PooledRouterOsConnector;
use App\Services\RouterOs\Testing\FakeRouterOsConnection;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * One connection per router, for the life of a request.
 *
 * Every conversation with a device costs a TCP handshake and a RouterOS login, and both are
 * round trips down a tunnel to somebody else's broadband. Issuing a HotSpot login used to pay
 * for two of them — one service checks the package profile, another writes the user — while a
 * customer who has just paid watches a spinner.
 */
class ConnectionReuseTest extends TestCase
{
    use RefreshDatabase;

    protected Router $router;

    protected function setUp(): void
    {
        parent::setUp();

        Tenancy::set(Tenant::factory()->create());

        $this->router = Router::factory()->create();
    }

    #[Test]
    public function two_services_asking_for_the_same_router_share_one_connection(): void
    {
        $counting = new CountingConnector;
        $pool = new PooledRouterOsConnector($counting);

        $first = $pool->connect($this->router);
        $first->disconnect();

        $second = $pool->connect($this->router);

        $this->assertSame(1, $counting->opened, 'The second ask should not have opened a socket.');
        $this->assertSame($first, $second);
    }

    #[Test]
    public function a_caller_closing_its_connection_does_not_close_it_for_everybody(): void
    {
        // Callers close when they are finished, which is right — they cannot know who else
        // wants the same router. The close is recorded and the socket kept.
        $counting = new CountingConnector;
        $pool = new PooledRouterOsConnector($counting);

        $connection = $pool->connect($this->router);
        $connection->disconnect();

        $connection->add('/ip/hotspot/user', ['name' => 'still-works']);

        $this->assertNotNull($counting->device->first('/ip/hotspot/user', ['name' => 'still-works']));
    }

    #[Test]
    public function different_routers_get_different_connections(): void
    {
        $counting = new CountingConnector;
        $pool = new PooledRouterOsConnector($counting);

        $other = Router::factory()->create();

        $this->assertNotSame($pool->connect($this->router), $pool->connect($other));
        $this->assertSame(2, $counting->opened);
    }

    #[Test]
    public function a_device_refusing_a_command_does_not_throw_the_connection_away(): void
    {
        // RouterOS answers a refused command with a trap rather than an error. That is the
        // router disagreeing with us, not the network failing, and the socket is still good.
        $counting = new CountingConnector;
        $pool = new PooledRouterOsConnector($counting);

        $counting->device->trap('/ip/hotspot/user', 'already have such name');

        $connection = $pool->connect($this->router);

        try {
            $connection->add('/ip/hotspot/user', ['name' => 'duplicate']);
        } catch (RouterOsCommandException) {
            // Expected.
        }

        $pool->connect($this->router);

        $this->assertSame(1, $counting->opened);
    }

    #[Test]
    public function a_connection_that_broke_underneath_us_is_replaced(): void
    {
        // A device rebooting or a tunnel re-handshaking kills the socket. Handing the corpse to
        // the next caller fails in a way that looks exactly like the router being down.
        $counting = new CountingConnector;
        $pool = new PooledRouterOsConnector($counting);

        $connection = $pool->connect($this->router);

        try {
            $connection->execute('/explode');
        } catch (RuntimeException) {
            // Expected: the fake raises this rather than a trap.
        }

        $pool->connect($this->router);

        $this->assertSame(2, $counting->opened, 'A broken connection should not be reused.');
    }
}

/**
 * A connector that counts how often it is actually asked to open something.
 */
class CountingConnector implements RouterOsConnector
{
    public int $opened = 0;

    public ExplodingFakeConnection $device;

    public function __construct()
    {
        $this->device = new ExplodingFakeConnection;
    }

    public function connect(Router $router): RouterOsConnection
    {
        $this->opened++;

        return $this->device;
    }
}

/**
 * The in-memory device, plus one command that fails the way a dead socket does.
 */
class ExplodingFakeConnection extends FakeRouterOsConnection
{
    /**
     * @param  array<string, string|int|bool>  $arguments
     * @return array<int, array<string, string>>
     */
    public function execute(string $command, array $arguments = []): array
    {
        if ($command === '/explode') {
            throw new RuntimeException('Connection reset by peer');
        }

        return parent::execute($command, $arguments);
    }
}
