<?php

namespace App\Support\Authorization;

/**
 * The platform's permission catalogue.
 *
 * This class is the single source of truth: PermissionSeeder writes these rows and
 * TenancyServiceProvider turns each one into a gate ability. Adding a capability means adding
 * it here and reseeding, never inserting a row by hand.
 */
class Permissions
{
    public const TENANTS_VIEW = 'tenants.view';

    public const TENANTS_CREATE = 'tenants.create';

    public const TENANTS_UPDATE = 'tenants.update';

    public const TENANTS_SUSPEND = 'tenants.suspend';

    public const USERS_VIEW = 'users.view';

    public const USERS_CREATE = 'users.create';

    public const USERS_UPDATE = 'users.update';

    public const USERS_DELETE = 'users.delete';

    public const ROLES_VIEW = 'roles.view';

    public const ROLES_ASSIGN = 'roles.assign';

    public const CUSTOMERS_VIEW = 'customers.view';

    public const CUSTOMERS_CREATE = 'customers.create';

    public const CUSTOMERS_UPDATE = 'customers.update';

    public const CUSTOMERS_DELETE = 'customers.delete';

    public const SERVICE_ACCOUNTS_VIEW = 'service_accounts.view';

    public const SERVICE_ACCOUNTS_CREATE = 'service_accounts.create';

    public const SERVICE_ACCOUNTS_UPDATE = 'service_accounts.update';

    public const SERVICE_ACCOUNTS_SUSPEND = 'service_accounts.suspend';

    public const PLANS_VIEW = 'plans.view';

    public const PLANS_CREATE = 'plans.create';

    public const PLANS_UPDATE = 'plans.update';

    public const PLANS_DELETE = 'plans.delete';

    public const SUBSCRIPTIONS_VIEW = 'subscriptions.view';

    public const SUBSCRIPTIONS_CREATE = 'subscriptions.create';

    public const SUBSCRIPTIONS_RENEW = 'subscriptions.renew';

    public const SUBSCRIPTIONS_CANCEL = 'subscriptions.cancel';

    public const INVOICES_VIEW = 'invoices.view';

    public const INVOICES_CREATE = 'invoices.create';

    public const INVOICES_UPDATE = 'invoices.update';

    public const INVOICES_VOID = 'invoices.void';

    public const PAYMENTS_VIEW = 'payments.view';

    public const PAYMENTS_RECORD = 'payments.record';

    public const PAYMENTS_REFUND = 'payments.refund';

    public const PAYMENTS_RECONCILE = 'payments.reconcile';

    public const ROUTERS_VIEW = 'routers.view';

    public const ROUTERS_CREATE = 'routers.create';

    public const ROUTERS_UPDATE = 'routers.update';

    public const ROUTERS_DELETE = 'routers.delete';

    public const ROUTERS_PROVISION = 'routers.provision';

    public const ROUTERS_COMMAND = 'routers.command';

    public const RADIUS_VIEW_SESSIONS = 'radius.view_sessions';

    public const RADIUS_DISCONNECT_SESSION = 'radius.disconnect_session';

    public const RADIUS_VIEW_ACCOUNTING = 'radius.view_accounting';

    public const VOUCHERS_VIEW = 'vouchers.view';

    public const VOUCHERS_GENERATE = 'vouchers.generate';

    public const VOUCHERS_REVOKE = 'vouchers.revoke';

    public const HOTSPOT_VIEW_PORTAL = 'hotspot.view_portal';

    public const HOTSPOT_UPDATE_PORTAL = 'hotspot.update_portal';

    public const REPORTS_VIEW_REVENUE = 'reports.view_revenue';

    public const REPORTS_VIEW_CUSTOMERS = 'reports.view_customers';

    public const REPORTS_VIEW_NETWORK = 'reports.view_network';

    public const REPORTS_EXPORT = 'reports.export';

    public const AUDIT_VIEW = 'audit.view';

    public const SETTINGS_VIEW = 'settings.view';

    public const SETTINGS_UPDATE = 'settings.update';

    public const SETTINGS_MANAGE_PAYMENT_CREDENTIALS = 'settings.manage_payment_credentials';

    /**
     * Every permission, grouped by module, as name => human label.
     *
     * @return array<string, array<string, string>>
     */
    public static function groups(): array
    {
        return [
            'tenants' => [
                self::TENANTS_VIEW => 'View ISP accounts',
                self::TENANTS_CREATE => 'Create ISP accounts',
                self::TENANTS_UPDATE => 'Update ISP accounts',
                self::TENANTS_SUSPEND => 'Suspend ISP accounts',
            ],
            'users' => [
                self::USERS_VIEW => 'View operators',
                self::USERS_CREATE => 'Invite operators',
                self::USERS_UPDATE => 'Update operators',
                self::USERS_DELETE => 'Remove operators',
            ],
            'roles' => [
                self::ROLES_VIEW => 'View roles',
                self::ROLES_ASSIGN => 'Assign roles',
            ],
            'customers' => [
                self::CUSTOMERS_VIEW => 'View customers',
                self::CUSTOMERS_CREATE => 'Create customers',
                self::CUSTOMERS_UPDATE => 'Update customers',
                self::CUSTOMERS_DELETE => 'Delete customers',
            ],
            'service_accounts' => [
                self::SERVICE_ACCOUNTS_VIEW => 'View service accounts',
                self::SERVICE_ACCOUNTS_CREATE => 'Create service accounts',
                self::SERVICE_ACCOUNTS_UPDATE => 'Update service accounts',
                self::SERVICE_ACCOUNTS_SUSPEND => 'Suspend service accounts',
            ],
            'plans' => [
                self::PLANS_VIEW => 'View plans',
                self::PLANS_CREATE => 'Create plans',
                self::PLANS_UPDATE => 'Update plans',
                self::PLANS_DELETE => 'Delete plans',
            ],
            'subscriptions' => [
                self::SUBSCRIPTIONS_VIEW => 'View subscriptions',
                self::SUBSCRIPTIONS_CREATE => 'Create subscriptions',
                self::SUBSCRIPTIONS_RENEW => 'Renew subscriptions',
                self::SUBSCRIPTIONS_CANCEL => 'Cancel subscriptions',
            ],
            'invoices' => [
                self::INVOICES_VIEW => 'View invoices',
                self::INVOICES_CREATE => 'Create invoices',
                self::INVOICES_UPDATE => 'Update invoices',
                self::INVOICES_VOID => 'Void invoices',
            ],
            'payments' => [
                self::PAYMENTS_VIEW => 'View payments',
                self::PAYMENTS_RECORD => 'Record payments',
                self::PAYMENTS_REFUND => 'Refund payments',
                self::PAYMENTS_RECONCILE => 'Reconcile payments',
            ],
            'routers' => [
                self::ROUTERS_VIEW => 'View routers',
                self::ROUTERS_CREATE => 'Add routers',
                self::ROUTERS_UPDATE => 'Update routers',
                self::ROUTERS_DELETE => 'Remove routers',
                self::ROUTERS_PROVISION => 'Provision routers',
                self::ROUTERS_COMMAND => 'Run router commands',
            ],
            'radius' => [
                self::RADIUS_VIEW_SESSIONS => 'View live sessions',
                self::RADIUS_DISCONNECT_SESSION => 'Disconnect sessions',
                self::RADIUS_VIEW_ACCOUNTING => 'View accounting data',
            ],
            'vouchers' => [
                self::VOUCHERS_VIEW => 'View vouchers',
                self::VOUCHERS_GENERATE => 'Generate voucher batches',
                self::VOUCHERS_REVOKE => 'Revoke vouchers',
            ],
            'hotspot' => [
                self::HOTSPOT_VIEW_PORTAL => 'View HotSpot portal settings',
                self::HOTSPOT_UPDATE_PORTAL => 'Update HotSpot portal settings',
            ],
            'reports' => [
                self::REPORTS_VIEW_REVENUE => 'View revenue reports',
                self::REPORTS_VIEW_CUSTOMERS => 'View customer reports',
                self::REPORTS_VIEW_NETWORK => 'View network reports',
                self::REPORTS_EXPORT => 'Export reports',
            ],
            'audit' => [
                self::AUDIT_VIEW => 'View audit log',
            ],
            'settings' => [
                self::SETTINGS_VIEW => 'View settings',
                self::SETTINGS_UPDATE => 'Update settings',
                self::SETTINGS_MANAGE_PAYMENT_CREDENTIALS => 'Manage payment credentials',
            ],
        ];
    }

    /**
     * Every permission name in the catalogue.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_merge(...array_map(
            'array_keys',
            array_values(self::groups()),
        ));
    }

    /**
     * Every permission name belonging to the given modules.
     *
     * @param  array<int, string>  $groups
     * @return array<int, string>
     */
    public static function forGroups(array $groups): array
    {
        $catalogue = self::groups();

        return array_merge(...array_map(
            fn (string $group): array => array_keys($catalogue[$group] ?? []),
            $groups,
        ));
    }
}
