<?php

namespace App\Services\RouterOs;

use App\Models\Router;
use App\Support\Audit\AuditLogger;
use Throwable;

/**
 * Puts the platform's API password back on a router that no longer accepts it.
 *
 * The platform keeps two logins for every router it built: its own API user, and the ISP's
 * administrator it created during the rebuild. When the two disagree with the device about the
 * first — a script pasted from a page that was regenerated afterwards, a password changed by
 * hand in Winbox — the router answers down the tunnel and refuses to talk. Asking an ISP to walk
 * to the router and paste a script to fix a password the platform itself knows is not a
 * reasonable answer. So the platform signs in as the administrator, sets its own API user's
 * password to what it has on record, and carries on.
 *
 * Uses the live connector directly rather than the pool: the pool is keyed by router, and this
 * connection speaks as a different user.
 */
class ApiCredentialRepair
{
    public function __construct(
        protected LiveRouterOsConnector $live,
        protected AuditLogger $audit,
    ) {}

    public function repair(Router $router): bool
    {
        if (blank($router->admin_username) || blank($router->admin_password) || blank($router->api_password) || blank($router->api_username)) {
            return false;
        }

        // A copy that speaks as the administrator. Never saved: the router's own credentials
        // stay the platform's API user.
        $asAdmin = clone $router;
        $asAdmin->api_username = (string) $router->admin_username;
        $asAdmin->api_password = (string) $router->admin_password;

        try {
            $connection = $this->live->connect($asAdmin);

            $user = $connection->first('/user', ['name' => $router->api_username]);

            if ($user !== null) {
                $connection->update('/user', $user['.id'], ['password' => (string) $router->api_password]);
            } else {
                // Gone altogether — removed by hand, or the rebuild stopped before making it.
                // Recreated with the same narrow group the setup script gives it, held to the
                // management subnet as before.
                $options = $router->setupOptions();
                $group = (string) ($options['api_group'] ?? 'platform-api');

                if ($connection->first('/user/group', ['name' => $group]) === null) {
                    $connection->add('/user/group', [
                        'name' => $group,
                        'policy' => 'read,write,test,api,sensitive,ftp,reboot',
                        'comment' => 'Platform provisioning: HotSpot users and portal files',
                    ]);
                }

                $connection->add('/user', [
                    'name' => (string) $router->api_username,
                    'password' => (string) $router->api_password,
                    'group' => $group,
                    'address' => (string) config('wireguard.subnet'),
                    'comment' => 'Platform. Do not remove.',
                ]);
            }

            $connection->disconnect();
        } catch (Throwable) {
            return false;
        }

        $this->audit->record(
            event: 'router.api_credentials_repaired',
            auditable: $router,
            description: sprintf('Reset the platform API password on %s using its administrator login', $router->name),
        );

        return true;
    }
}
