<?php

namespace App\Services\RouterOs\Console;

use App\Enums\HotspotUserStatus;
use App\Models\HotspotUser;
use App\Models\PlanRouterProfile;
use App\Models\Router;
use App\Models\ServiceAccount;

/**
 * What on a router the platform keeps in step with its own database.
 *
 * A plan's profile, a sold HotSpot login and a PPPoE account all exist twice: as a row on the
 * device and as a record here that billing, expiry and earnings are built from. Editing the
 * device copy from the console would succeed and then be quietly undone by the next sync — or
 * worse, not undone, leaving the two disagreeing about what a customer bought. So the console
 * shows those rows and points at where they are actually managed.
 *
 * Looked up once per router per request: a table of five hundred HotSpot users asks whether
 * each row is protected, and five hundred queries to answer that would be the slow part of
 * the page.
 */
class PlatformRecords
{
    /** @var array<int, array<string, string>> */
    protected array $profiles = [];

    /** @var array<int, array<string, int>> */
    protected array $hotspotUsers = [];

    /** @var array<int, array<string, string>> */
    protected array $pppAccounts = [];

    /**
     * The plan a profile of this name was pushed from, if any.
     */
    public function planProfile(Router $router, string $name): ?string
    {
        $this->profiles[$router->id] ??= PlanRouterProfile::query()
            ->where('router_id', $router->id)
            ->with('plan:id,name')
            ->get()
            ->mapWithKeys(fn (PlanRouterProfile $pairing): array => [$pairing->profile_name => (string) $pairing->plan?->name])
            ->all();

        return $this->profiles[$router->id][$name] ?? null;
    }

    /**
     * Whether a HotSpot login of this name was sold through the platform and is still live.
     */
    public function soldHotspotUser(Router $router, string $name): bool
    {
        $this->hotspotUsers[$router->id] ??= HotspotUser::query()
            ->where('router_id', $router->id)
            ->whereIn('status', [HotspotUserStatus::Active, HotspotUserStatus::Pending, HotspotUserStatus::Failed])
            ->pluck('username')
            ->flip()
            ->all();

        return isset($this->hotspotUsers[$router->id][$name]);
    }

    /**
     * The account number a PPPoE login of this name belongs to, if any.
     */
    public function pppAccount(Router $router, string $name): ?string
    {
        $this->pppAccounts[$router->id] ??= ServiceAccount::query()
            ->where('router_id', $router->id)
            ->pluck('account_number', 'username')
            ->map(fn ($number): string => (string) $number)
            ->all();

        return $this->pppAccounts[$router->id][$name] ?? null;
    }
}
