<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * The other half of every router's setup script.
 *
 * WireGuard is mutual: a router that knows about us gets nowhere unless we also know about
 * it. This renders the peer entries the management server needs, across every ISP — the
 * tunnel is one network, so this is one of the few places where spanning tenants is the point
 * rather than a mistake.
 *
 * It prints rather than applies by default. Rewriting a live server's tunnel configuration is
 * not something to do as a side effect of running a report, and the web user has no business
 * holding the privileges it would need.
 */
class WireguardPeersCommand extends Command
{
    protected $signature = 'wireguard:peers
        {--apply : Load the peers into the running interface with wg. Must run as root.}
        {--interface=wg0 : The management interface on this server.}
        {--config : Print [Peer] blocks for wg0.conf instead of wg commands.}
        {--plain : Print "public-key address" per line, for the root helper to apply.}';

    protected $description = 'Render the WireGuard peer entries for every router';

    public function handle(): int
    {
        $routers = $this->routers();

        if ($routers->isEmpty()) {
            $this->components->info('No router has been given a tunnel yet.');

            return self::SUCCESS;
        }

        $interface = (string) $this->option('interface');

        if ($this->option('config')) {
            $this->line($this->asConfig($routers));

            return self::SUCCESS;
        }

        // For deploy/bin/isp-wg-sync: the application lists, root applies. Nothing here runs
        // with privileges, and the helper checks every line before it acts on it.
        if ($this->option('plain')) {
            $routers->each(fn (Router $router) => $this->line($router->wg_public_key.' '.$router->wg_address));

            return self::SUCCESS;
        }

        $commands = $routers->map(fn (Router $router): string => sprintf(
            'wg set %s peer %s allowed-ips %s/32',
            $interface,
            (string) $router->wg_public_key,
            (string) $router->wg_address,
        ));

        if (! $this->option('apply')) {
            $this->components->info(sprintf('%d routers. Run these on the management server as root:', $routers->count()));
            $this->newLine();
            $commands->each(fn (string $command) => $this->line('  '.$command));
            $this->newLine();
            $this->components->warn('Re-run with --apply to load them, or --config for wg0.conf.');

            return self::SUCCESS;
        }

        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            $this->components->error('--apply changes the running tunnel and has to be run as root.');

            return self::FAILURE;
        }

        $failed = 0;

        foreach ($commands as $command) {
            exec($command.' 2>&1', $output, $status);

            if ($status !== 0) {
                $failed++;
                $this->components->error(trim(implode(' ', $output)));
            }

            $output = [];
        }

        $this->components->info(sprintf('%d peers loaded, %d failed.', $routers->count() - $failed, $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  Collection<int, Router>  $routers
     */
    protected function asConfig(Collection $routers): string
    {
        return $routers->map(fn (Router $router): string => implode("\n", [
            '',
            '# '.$router->name.' — tenant '.$router->tenant_id,
            '[Peer]',
            'PublicKey = '.$router->wg_public_key,
            'AllowedIPs = '.$router->wg_address.'/32',
        ]))->implode("\n");
    }

    /**
     * @return Collection<int, Router>
     */
    protected function routers(): Collection
    {
        // One tunnel serves every ISP, so this deliberately spans all of them.
        return Tenancy::withoutScope(fn (): Collection => Router::query()
            ->withoutGlobalScopes()
            ->whereNotNull('wg_public_key')
            ->whereNotNull('wg_address')
            ->orderBy('wg_address')
            ->get());
    }
}
