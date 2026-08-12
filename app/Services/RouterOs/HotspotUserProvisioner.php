<?php

namespace App\Services\RouterOs;

use App\Enums\HotspotUserStatus;
use App\Enums\PlanType;
use App\Models\HotspotUser;
use App\Models\Plan;
use App\Models\Router;
use App\Services\RouterOs\Exceptions\RouterOsCommandException;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Puts paid HotSpot customers onto the router and takes them off again.
 *
 * The username and password are both the payment reference, so a customer has one code to
 * remember and the captive portal can log them in without asking for anything.
 */
class HotspotUserProvisioner
{
    protected const MENU = '/ip/hotspot/user';

    public function __construct(
        protected Contracts\RouterOsConnector $connector,
        protected PlanProfileSynchroniser $profiles,
        protected AuditLogger $audit,
    ) {}

    /**
     * Record a purchase and write it to the router.
     *
     * The record is created first and separately from the push, so a router that is briefly
     * unreachable leaves a customer who has paid with a recoverable row rather than nothing.
     *
     * **One reference is one package, however many times this is called.** A provider's webhook
     * and the portal's own polling routinely reach the same conclusion within the same second,
     * and both then ask for the access that payment bought. Guarding on the payment's
     * `hotspot_user_id` is not enough: both read it before either has written. Until this was
     * made idempotent here, the loser of that race hit the unique index and the customer — who
     * had paid a moment earlier — was shown a server error.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function issue(Plan $plan, Router $router, string $reference, array $attributes = []): HotspotUser
    {
        if ($plan->type !== PlanType::Hotspot) {
            throw new InvalidArgumentException(
                sprintf('Plan [%s] is a %s plan and cannot be issued as a HotSpot login.', $plan->code, $plan->type->value),
            );
        }

        $user = $this->record($plan, $router, $reference, $attributes);

        return $this->provision($user);
    }

    /**
     * The purchase row for this reference, creating it only if nobody else already has.
     *
     * Checked first and caught as well. The check answers the ordinary case — a repeat callback
     * arriving seconds later — without paying for a failed insert, and the catch answers the
     * genuine race, where two requests both looked before either wrote. Only the database can
     * settle that one, and it does: the unique index on (router, username) is the arbiter, and
     * the loser reads back what the winner created rather than failing.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function record(Plan $plan, Router $router, string $reference, array $attributes): HotspotUser
    {
        if (($existing = $this->purchased($router, $reference)->first()) !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($plan, $router, $reference, $attributes): HotspotUser {
                $user = new HotspotUser(array_merge([
                    'plan_id' => $plan->id,
                    'router_id' => $router->id,
                    // Username and password are deliberately the same value.
                    'username' => $reference,
                    'password' => $reference,
                    'reference' => $reference,
                    'amount' => $plan->price,
                    'limit_uptime_seconds' => $plan->validitySeconds(),
                    'limit_bytes' => $plan->dataCapBytes(),
                    'expires_at' => now()->addSeconds($plan->validitySeconds()),
                ], $attributes));

                $user->save();

                return $user;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Somebody else got there between the look and the insert. Their row is the one this
            // customer has, so it is read back rather than treated as a failure — and if there
            // is nothing to read, some other constraint rejected this and the original is
            // rethrown rather than swallowed.
            return $this->purchased($router, $reference)->firstOr(fn () => throw $e);
        }
    }

    /**
     * The purchase this reference names on this device, if it has been recorded.
     *
     * @return Builder<HotspotUser>
     */
    protected function purchased(Router $router, string $reference)
    {
        return HotspotUser::query()
            ->where('router_id', $router->id)
            ->where('username', $reference);
    }

    /**
     * Write a recorded package onto its router.
     *
     * Never throws: the customer has already paid, so a failure is recorded against the row for
     * the sweeper to retry rather than thrown back at whatever handled the payment.
     */
    public function provision(HotspotUser $user): HotspotUser
    {
        $user->loadMissing(['plan', 'router']);

        try {
            $router = $user->router;
            $plan = $user->plan;

            // The plan's profile has to exist on the device before a user can reference it.
            // ensure() rather than sync(): a customer who has just paid should not wait for a
            // profile to be rewritten that nothing has changed since the last sale.
            $pairing = $this->profiles->ensure($plan, $router);

            if (! $pairing->isSynced()) {
                throw new RouterOsCommandException(sprintf(
                    'Plan profile could not be prepared on %s: %s',
                    $router->name,
                    $pairing->sync_error,
                ));
            }

            $connection = $this->connector->connect($router);

            // Re-issuing the same reference must not create a second row: M-PESA callbacks
            // arrive more than once, and a duplicate would be rejected by the device anyway.
            $existing = $connection->first(self::MENU, ['name' => $user->username]);

            $attributes = $this->attributesFor($user, $pairing->profile_name);

            if ($existing === null) {
                $user->routeros_id = $connection->add(self::MENU, $attributes);
            } else {
                unset($attributes['name']);
                $connection->update(self::MENU, $existing['.id'], $attributes);
                $user->routeros_id = $existing['.id'];
            }

            $connection->disconnect();

            $user->status = HotspotUserStatus::Active;
            $user->provisioned_at = now();
            $user->last_error = null;
            $user->save();

            $this->audit->record(
                event: 'hotspot_user.provisioned',
                auditable: $user,
                description: sprintf('Issued %s on %s for plan %s', $user->username, $router->name, $plan->name),
            );
        } catch (Throwable $e) {
            $user->status = HotspotUserStatus::Failed;
            $user->last_error = $e->getMessage();
            $user->save();
        }

        return $user;
    }

    /**
     * Take a package off the router, keeping the record.
     *
     * Usage is read back first: once the row is gone the device has no memory of it, and that
     * reading is the only evidence of what the customer actually consumed.
     */
    public function remove(HotspotUser $user, HotspotUserStatus $status = HotspotUserStatus::Expired): HotspotUser
    {
        $user->loadMissing('router');

        try {
            $connection = $this->connector->connect($user->router);

            $existing = $connection->first(self::MENU, ['name' => $user->username]);

            if ($existing !== null) {
                $user->used_uptime_seconds = $this->parseUptime($existing['uptime'] ?? null);
                $user->used_bytes = $this->usedBytes($existing);

                $connection->remove(self::MENU, $existing['.id']);
            }

            // Deleting the user does not close a session already running, so any live login
            // has to be knocked off separately.
            $this->disconnectActiveSession($connection, $user->username);

            $connection->disconnect();

            $user->status = $status;
            $user->removed_at = now();
            $user->routeros_id = null;
            $user->last_error = null;
            $user->save();

            $this->audit->record(
                event: 'hotspot_user.removed',
                auditable: $user,
                description: sprintf('Removed %s from %s (%s)', $user->username, $user->router->name, $status->value),
            );
        } catch (Throwable $e) {
            $user->last_error = $e->getMessage();
            $user->save();
        }

        return $user;
    }

    /**
     * Knock a logged-in customer off, so a withdrawn package stops working immediately.
     */
    protected function disconnectActiveSession(Contracts\RouterOsConnection $connection, string $username): void
    {
        try {
            $active = $connection->first('/ip/hotspot/active', ['user' => $username]);

            if ($active !== null) {
                $connection->remove('/ip/hotspot/active', $active['.id']);
            }
        } catch (Throwable) {
            // The session may have ended between the two calls, and the package is gone
            // either way. Not worth failing the removal over.
        }
    }

    /**
     * @return array<string, string|int|bool>
     */
    protected function attributesFor(HotspotUser $user, string $profileName): array
    {
        $attributes = [
            'name' => $user->username,
            'password' => $user->password,
            'profile' => $profileName,
            // "all" rather than a named server: an ISP may run more than one HotSpot on a
            // device, and the package is not sold against a particular one.
            'server' => 'all',
            'comment' => $this->comment($user),
        ];

        // Per-customer allowances live on the user, not the shared profile: two people on the
        // same plan each bought their own hour.
        if ($user->limit_uptime_seconds !== null) {
            $attributes['limit-uptime'] = $user->limit_uptime_seconds.'s';
        }

        if ($user->limit_bytes !== null) {
            $attributes['limit-bytes-total'] = $user->limit_bytes;
        }

        return $attributes;
    }

    protected function comment(HotspotUser $user): string
    {
        return sprintf(
            '%s | %s | expires %s',
            $user->plan->name,
            $user->phone ?? $user->reference ?? 'walk-in',
            $user->expires_at?->toDateTimeString() ?? 'never',
        );
    }

    /**
     * @param  array<string, string>  $row
     */
    protected function usedBytes(array $row): ?int
    {
        $in = isset($row['bytes-in']) ? (int) $row['bytes-in'] : null;
        $out = isset($row['bytes-out']) ? (int) $row['bytes-out'] : null;

        return $in === null && $out === null ? null : (int) $in + (int) $out;
    }

    /**
     * RouterOS reports uptime as "1h30m20s".
     */
    protected function parseUptime(?string $uptime): ?int
    {
        if ($uptime === null || $uptime === '') {
            return null;
        }

        if (preg_match_all('/(\d+)([wdhms])/', $uptime, $matches, PREG_SET_ORDER) === 0) {
            return null;
        }

        $seconds = 0;

        foreach ($matches as [, $value, $unit]) {
            $seconds += (int) $value * match ($unit) {
                'w' => 604800,
                'd' => 86400,
                'h' => 3600,
                'm' => 60,
                's' => 1,
            };
        }

        return $seconds;
    }

    /**
     * Generate a code for a package sold outside M-PESA, e.g. over the counter.
     *
     * Deliberately shaped like an M-PESA receipt — uppercase and unambiguous — so operators
     * and customers handle one kind of code.
     */
    public static function generateReference(): string
    {
        do {
            // No I, O, 0 or 1: these are read aloud and typed on phone keypads.
            $code = collect(str_split('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'))
                ->shuffle()
                ->take(10)
                ->implode('');

            $taken = Tenancy::withoutScope(
                fn (): bool => HotspotUser::query()->withoutGlobalScopes()->where('username', $code)->exists(),
            );
        } while ($taken);

        return $code;
    }
}
