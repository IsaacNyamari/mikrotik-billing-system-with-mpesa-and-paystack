<?php

namespace Tests\Concerns;

use App\Services\RouterOs\Contracts\RouterOsConnector;
use App\Services\RouterOs\Testing\FakeRouterOsConnector;

trait InteractsWithRouters
{
    protected FakeRouterOsConnector $routers;

    /**
     * Replace the live RouterOS connector with in-memory devices for the rest of the test.
     */
    protected function fakeRouters(): FakeRouterOsConnector
    {
        $this->routers = new FakeRouterOsConnector;

        $this->app->instance(RouterOsConnector::class, $this->routers);

        return $this->routers;
    }

    /**
     * The readings a healthy MikroTik reports about itself.
     *
     * @return array<string, string>
     */
    protected function systemResource(array $overrides = []): array
    {
        return array_merge([
            'uptime' => '6w2d3h4m5s',
            'version' => '7.14.3',
            'board-name' => 'RB750Gr3',
            'cpu-load' => '7',
            'free-memory' => '180000000',
            'total-memory' => '256000000',
        ], $overrides);
    }
}
