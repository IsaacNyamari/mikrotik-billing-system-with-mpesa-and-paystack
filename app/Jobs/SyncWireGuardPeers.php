<?php

namespace App\Jobs;

use App\Services\Provisioning\WireGuardPeerSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tells this server's tunnel about every router that has one.
 *
 * WireGuard is mutual: a router that has been given our key gets nowhere until we have its
 * key too. That used to be a command somebody ran on the server, which an ISP cannot do and
 * should never be asked to — so it happens on its own, whenever a router is given tunnel keys
 * and again when a wiped router downloads its configuration.
 *
 * Unique, because the triggers bunch up — a setup page renders, a key is saved, a router
 * fetches its file — and one sync covers all of them: it applies every peer, not just one.
 */
class SyncWireGuardPeers implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 30;

    public int $tries = 3;

    public int $backoff = 10;

    public function handle(WireGuardPeerSync $sync): void
    {
        $sync->sync();
    }
}
