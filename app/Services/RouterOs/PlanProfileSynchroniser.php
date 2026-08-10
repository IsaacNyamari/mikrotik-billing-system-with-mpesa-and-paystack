<?php

namespace App\Services\RouterOs;

use App\Enums\PlanType;
use App\Models\Plan;
use App\Models\PlanRouterProfile;
use App\Models\Router;
use App\Services\RouterOs\Contracts\RouterOsConnection;
use App\Services\RouterOs\Contracts\RouterOsConnector;
use App\Support\Audit\AuditLogger;
use Throwable;

/**
 * Copies a plan onto a router as a RouterOS profile.
 *
 * The profile carries the settings shared by everyone on the plan — speed and how many devices
 * may use one login at once. Per-customer quotas (time and data) are not set here: they belong
 * to the individual user row, because two people on the same plan buy their own hour.
 */
class PlanProfileSynchroniser
{
    public function __construct(
        protected RouterOsConnector $connector,
        protected AuditLogger $audit,
    ) {}

    /**
     * Push one plan to one router.
     *
     * Never throws: a router being unreachable is an ordinary outcome that gets recorded
     * against the pairing so an operator can see and retry it.
     */
    public function sync(Plan $plan, Router $router): PlanRouterProfile
    {
        $pairing = $this->pairing($plan, $router);

        try {
            $connection = $this->connector->connect($router);

            $pairing->routeros_id = $this->writeProfile($connection, $plan, $pairing->profile_name);

            $connection->disconnect();

            $pairing->synced_at = now();
            $pairing->sync_error = null;
            $pairing->save();

            $this->audit->record(
                event: 'plan.pushed_to_router',
                auditable: $plan,
                description: sprintf('Pushed plan %s to router %s as profile %s', $plan->name, $router->name, $pairing->profile_name),
            );
        } catch (Throwable $e) {
            $pairing->sync_error = $e->getMessage();
            $pairing->save();
        }

        return $pairing;
    }

    /**
     * Push a plan to every router this ISP has.
     *
     * @return array<int, PlanRouterProfile>
     */
    public function syncToAllRouters(Plan $plan): array
    {
        return Router::query()
            ->get()
            ->map(fn (Router $router): PlanRouterProfile => $this->sync($plan, $router))
            ->all();
    }

    /**
     * Remove a plan's profile from a router.
     *
     * RouterOS refuses to delete a profile that users still reference, which is the behaviour
     * we want: it stops a plan being withdrawn out from under people who are still online.
     */
    public function remove(Plan $plan, Router $router): void
    {
        $pairing = PlanRouterProfile::query()
            ->where('plan_id', $plan->id)
            ->where('router_id', $router->id)
            ->first();

        if ($pairing === null) {
            return;
        }

        try {
            $connection = $this->connector->connect($router);

            $existing = $connection->first($plan->type->profileMenu(), ['name' => $pairing->profile_name]);

            if ($existing !== null) {
                $connection->remove($plan->type->profileMenu(), $existing['.id']);
            }

            $connection->disconnect();

            $pairing->delete();

            $this->audit->record(
                event: 'plan.removed_from_router',
                auditable: $plan,
                description: sprintf('Removed profile %s from router %s', $pairing->profile_name, $router->name),
            );
        } catch (Throwable $e) {
            $pairing->sync_error = $e->getMessage();
            $pairing->save();

            throw $e;
        }
    }

    /**
     * Create or update the profile on the device, returning its RouterOS id.
     *
     * A profile already carrying this name is adopted and updated rather than duplicated —
     * RouterOS profile names are unique per menu, and an ISP that built profiles by hand
     * before adopting the platform should not end up with two of everything.
     */
    protected function writeProfile(RouterOsConnection $connection, Plan $plan, string $name): string
    {
        $menu = $plan->type->profileMenu();
        $attributes = $this->attributesFor($plan, $name);

        $existing = $connection->first($menu, ['name' => $name]);

        if ($existing === null) {
            return $connection->add($menu, $attributes);
        }

        // "name" is the thing we matched on, so re-sending it is noise.
        unset($attributes['name']);

        $connection->update($menu, $existing['.id'], $attributes);

        return $existing['.id'];
    }

    /**
     * @return array<string, string|int|bool>
     */
    protected function attributesFor(Plan $plan, string $name): array
    {
        return match ($plan->type) {
            PlanType::Hotspot => $this->hotspotProfile($plan, $name),
            PlanType::Pppoe => $this->pppProfile($plan, $name),
        };
    }

    /**
     * @return array<string, string|int|bool>
     */
    protected function hotspotProfile(Plan $plan, string $name): array
    {
        $attributes = [
            'name' => $name,
            'shared-users' => $plan->shared_users,
            // Lets a customer's phone reconnect without logging in again for the life of the
            // cookie, which is what makes a paid hour feel continuous as they move around.
            'add-mac-cookie' => true,
            'mac-cookie-timeout' => $plan->routerOsUptimeLimit(),
        ];

        if (($rate = $plan->routerOsRateLimit()) !== null) {
            $attributes['rate-limit'] = $rate;
        }

        // "none" rather than omitting it: an operator may have set an idle timeout by hand,
        // and leaving the key out would silently keep their value.
        $attributes['idle-timeout'] = $plan->idle_timeout_minutes === null
            ? 'none'
            : ($plan->idle_timeout_minutes * 60).'s';

        return $attributes;
    }

    /**
     * @return array<string, string|int|bool>
     */
    protected function pppProfile(Plan $plan, string $name): array
    {
        $attributes = [
            'name' => $name,
            // A PPPoE line is one household: a second login with the same credentials should
            // replace the first rather than run alongside it.
            'only-one' => $plan->shared_users <= 1 ? 'yes' : 'no',
        ];

        if (($rate = $plan->routerOsRateLimit()) !== null) {
            $attributes['rate-limit'] = $rate;
        }

        return $attributes;
    }

    protected function pairing(Plan $plan, Router $router): PlanRouterProfile
    {
        $pairing = PlanRouterProfile::query()->firstOrNew([
            'plan_id' => $plan->id,
            'router_id' => $router->id,
        ]);

        $pairing->profile_name ??= $plan->code;

        if (! $pairing->exists) {
            $pairing->save();
        }

        return $pairing;
    }
}
