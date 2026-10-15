<?php

namespace App\Services\Provisioning;

use App\Models\Router;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Loads a router's peer into the management server's own tunnel.
 *
 * WireGuard is mutual: a router that knows about us gets nowhere unless we know about it. That
 * used to mean an operator opening a terminal on the server, which is not something an ISP
 * should ever need to do to add a router of theirs.
 *
 * Changing a live tunnel needs root, and the web user emphatically should not have it. So the
 * privilege is drawn as narrowly as it can be: one wrapper script that takes **no arguments**
 * and only ever syncs peers already in the database, reachable through a single sudoers line.
 * There is nothing to inject into and nothing to choose — the worst an attacker with the web
 * user could do is re-apply peers that are already there.
 */
class WireGuardPeerSync
{
    /**
     * Whether this installation can do it without an operator opening a terminal.
     */
    public function isAvailable(): bool
    {
        return is_string(config('wireguard.sync_command')) && config('wireguard.sync_command') !== '';
    }

    /**
     * Push every known router's peer onto the server's interface.
     *
     * Syncs all of them rather than the one that prompted it: `wg set` is additive and
     * idempotent, and a peer that went missing during a server rebuild is fixed by the next
     * router anyone adds instead of staying broken until somebody notices.
     *
     * @return array{ok: bool, message: string}
     */
    public function sync(?Router $router = null): array
    {
        if (! $this->isAvailable()) {
            return [
                'ok' => false,
                // No server command in this message: it is read by ISPs, who have no access to
                // the server and should never be told to use it.
                'message' => __('Automatic tunnel setup is not switched on for this platform yet. The platform administrator has been told.'),
            ];
        }

        try {
            $result = Process::timeout(30)->run((string) config('wireguard.sync_command'));
        } catch (\Throwable $e) {
            Log::warning('WireGuard peer sync failed to start', ['error' => $e->getMessage()]);

            return ['ok' => false, 'message' => __('Could not run the peer sync on this server.')];
        }

        if (! $result->successful()) {
            Log::warning('WireGuard peer sync failed', [
                'router' => $router?->id,
                'exit' => $result->exitCode(),
                'output' => $result->errorOutput() ?: $result->output(),
            ]);

            // The operator gets something they can act on; the detail is in the log, because
            // sudo and wg errors are not written for them.
            return ['ok' => false, 'message' => __('The peer sync did not complete. An administrator should check the server log.')];
        }

        Log::info('WireGuard peers synced', ['router' => $router?->id]);

        return ['ok' => true, 'message' => __('The server now knows about this router.')];
    }
}
