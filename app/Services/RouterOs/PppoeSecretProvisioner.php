<?php

namespace App\Services\RouterOs;

use App\Enums\ServiceType;
use App\Models\ServiceAccount;
use App\Services\RouterOs\Exceptions\RouterOsCommandException;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use Throwable;

/**
 * Keeps a PPPoE service account and its `/ppp/secret` row in step.
 *
 * Unlike HotSpot, PPPoE credentials are created by the ISP and handed to a named customer, so
 * the row lives on the router for as long as the account is active and is disabled rather than
 * deleted when the account is suspended — a suspended line usually comes back.
 */
class PppoeSecretProvisioner
{
    protected const MENU = '/ppp/secret';

    public function __construct(
        protected Contracts\RouterOsConnector $connector,
        protected PlanProfileSynchroniser $profiles,
        protected AuditLogger $audit,
    ) {}

    /**
     * Write the account to its router, or update what is already there.
     *
     * Never throws: an unreachable router is recorded on the account so an operator can see
     * that the device is out of step and retry.
     */
    public function push(ServiceAccount $account): ServiceAccount
    {
        $this->guard($account);

        $account->loadMissing(['plan', 'router', 'customer']);

        try {
            $router = $account->router;
            $plan = $account->plan;

            $profileName = 'default';

            if ($plan !== null) {
                $pairing = $this->profiles->sync($plan, $router);

                if (! $pairing->isSynced()) {
                    throw new RouterOsCommandException(sprintf(
                        'Plan profile could not be prepared on %s: %s',
                        $router->name,
                        $pairing->sync_error,
                    ));
                }

                $profileName = $pairing->profile_name;
            }

            $connection = $this->connector->connect($router);

            $attributes = [
                'name' => $account->username,
                'password' => (string) $account->secret,
                'profile' => $profileName,
                'service' => 'pppoe',
                'comment' => $this->comment($account),
                // A suspended line keeps its credentials but stops working.
                'disabled' => ! $account->allowsAuthentication(),
            ];

            $existing = $connection->first(self::MENU, ['name' => $account->username]);

            if ($existing === null) {
                $account->routeros_id = $connection->add(self::MENU, $attributes);
            } else {
                unset($attributes['name']);
                $connection->update(self::MENU, $existing['.id'], $attributes);
                $account->routeros_id = $existing['.id'];
            }

            // Disabling a secret leaves an established session running, so a suspension only
            // bites once the customer is knocked off.
            if (! $account->allowsAuthentication()) {
                $this->disconnectActiveSession($connection, $account->username);
            }

            $connection->disconnect();

            $account->provisioned_at = now();
            $account->provision_error = null;
            $account->save();

            $this->audit->record(
                event: 'service_account.pushed_to_router',
                auditable: $account,
                description: sprintf('Pushed %s to %s', $account->username, $router->name),
            );
        } catch (Throwable $e) {
            $account->provision_error = $e->getMessage();
            $account->save();
        }

        return $account;
    }

    /**
     * Remove the account from the router entirely, for a terminated line.
     */
    public function remove(ServiceAccount $account): ServiceAccount
    {
        $this->guard($account);

        $account->loadMissing('router');

        try {
            $connection = $this->connector->connect($account->router);

            $existing = $connection->first(self::MENU, ['name' => $account->username]);

            if ($existing !== null) {
                $connection->remove(self::MENU, $existing['.id']);
            }

            $this->disconnectActiveSession($connection, $account->username);
            $connection->disconnect();

            $account->routeros_id = null;
            $account->provisioned_at = null;
            $account->provision_error = null;
            $account->save();

            $this->audit->record(
                event: 'service_account.removed_from_router',
                auditable: $account,
                description: sprintf('Removed %s from %s', $account->username, $account->router->name),
            );
        } catch (Throwable $e) {
            $account->provision_error = $e->getMessage();
            $account->save();
        }

        return $account;
    }

    protected function disconnectActiveSession(Contracts\RouterOsConnection $connection, string $username): void
    {
        try {
            $active = $connection->first('/ppp/active', ['name' => $username]);

            if ($active !== null) {
                $connection->remove('/ppp/active', $active['.id']);
            }
        } catch (Throwable) {
            // The session may have dropped on its own between the two calls.
        }
    }

    protected function comment(ServiceAccount $account): string
    {
        return sprintf(
            '%s | %s',
            $account->account_number,
            $account->customer?->displayName() ?? 'unassigned',
        );
    }

    protected function guard(ServiceAccount $account): void
    {
        if ($account->type !== ServiceType::Pppoe) {
            throw new InvalidArgumentException(sprintf(
                'Service account %s is a %s service, not PPPoE.',
                $account->account_number,
                $account->type->value,
            ));
        }

        if ($account->router_id === null) {
            throw new InvalidArgumentException(sprintf(
                'Service account %s has no router to provision onto.',
                $account->account_number,
            ));
        }
    }
}
