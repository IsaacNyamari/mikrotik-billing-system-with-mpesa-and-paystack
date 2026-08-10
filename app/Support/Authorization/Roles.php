<?php

namespace App\Support\Authorization;

/**
 * The platform-defined roles from the specification.
 *
 * Each ISP gets these for free; a tenant may define extra roles of its own on top.
 */
class Roles
{
    public const PLATFORM_SUPER_ADMIN = 'platform_super_admin';

    public const ISP_OWNER = 'isp_owner';

    public const ISP_ADMIN = 'isp_admin';

    public const BILLING_OFFICER = 'billing_officer';

    public const NETWORK_ENGINEER = 'network_engineer';

    public const SUPPORT_AGENT = 'support_agent';

    public const READ_ONLY = 'read_only';

    /**
     * Role definitions as name => [label, description, permissions].
     *
     * A permissions value of ['*'] means the whole catalogue.
     *
     * @return array<string, array{label: string, description: string, permissions: array<int, string>}>
     */
    public static function definitions(): array
    {
        return [
            self::PLATFORM_SUPER_ADMIN => [
                'label' => 'Platform Super Admin',
                'description' => 'All tenants and platform controls.',
                'permissions' => ['*'],
            ],
            self::ISP_OWNER => [
                'label' => 'ISP Owner',
                'description' => 'Everything inside their own ISP.',
                'permissions' => Permissions::forGroups([
                    'users', 'roles', 'customers', 'service_accounts', 'plans', 'subscriptions',
                    'invoices', 'payments', 'routers', 'radius', 'vouchers', 'hotspot',
                    'reports', 'audit', 'settings',
                ]),
            ],
            self::ISP_ADMIN => [
                'label' => 'ISP Admin',
                'description' => 'Customers, routers, plans, billing and reports.',
                'permissions' => array_merge(
                    Permissions::forGroups([
                        'customers', 'service_accounts', 'plans', 'subscriptions', 'invoices',
                        'payments', 'routers', 'radius', 'vouchers', 'hotspot', 'reports',
                    ]),
                    [
                        Permissions::USERS_VIEW,
                        Permissions::ROLES_VIEW,
                        Permissions::SETTINGS_VIEW,
                    ],
                ),
            ],
            self::BILLING_OFFICER => [
                'label' => 'Billing Officer',
                'description' => 'Invoices, payments, subscriptions and reports.',
                'permissions' => array_merge(
                    Permissions::forGroups(['invoices', 'payments', 'subscriptions', 'reports']),
                    [
                        Permissions::CUSTOMERS_VIEW,
                        Permissions::SERVICE_ACCOUNTS_VIEW,
                        Permissions::PLANS_VIEW,
                        Permissions::VOUCHERS_VIEW,
                    ],
                ),
            ],
            self::NETWORK_ENGINEER => [
                'label' => 'Network Engineer',
                'description' => 'Routers, RADIUS and diagnostics.',
                'permissions' => array_merge(
                    Permissions::forGroups(['routers', 'radius', 'hotspot']),
                    [
                        Permissions::CUSTOMERS_VIEW,
                        Permissions::SERVICE_ACCOUNTS_VIEW,
                        Permissions::SERVICE_ACCOUNTS_SUSPEND,
                        Permissions::PLANS_VIEW,
                        Permissions::REPORTS_VIEW_NETWORK,
                    ],
                ),
            ],
            self::SUPPORT_AGENT => [
                'label' => 'Support Agent',
                'description' => 'Customer and support operations.',
                'permissions' => [
                    Permissions::CUSTOMERS_VIEW,
                    Permissions::CUSTOMERS_CREATE,
                    Permissions::CUSTOMERS_UPDATE,
                    Permissions::SERVICE_ACCOUNTS_VIEW,
                    Permissions::SERVICE_ACCOUNTS_CREATE,
                    Permissions::SERVICE_ACCOUNTS_UPDATE,
                    Permissions::PLANS_VIEW,
                    Permissions::SUBSCRIPTIONS_VIEW,
                    Permissions::INVOICES_VIEW,
                    Permissions::PAYMENTS_VIEW,
                    Permissions::VOUCHERS_VIEW,
                    Permissions::RADIUS_VIEW_SESSIONS,
                    Permissions::ROUTERS_VIEW,
                    Permissions::REPORTS_VIEW_CUSTOMERS,
                ],
            ],
            self::READ_ONLY => [
                'label' => 'Read Only',
                'description' => 'Dashboards and reports only.',
                'permissions' => [
                    Permissions::CUSTOMERS_VIEW,
                    Permissions::SERVICE_ACCOUNTS_VIEW,
                    Permissions::PLANS_VIEW,
                    Permissions::SUBSCRIPTIONS_VIEW,
                    Permissions::INVOICES_VIEW,
                    Permissions::PAYMENTS_VIEW,
                    Permissions::ROUTERS_VIEW,
                    Permissions::VOUCHERS_VIEW,
                    Permissions::RADIUS_VIEW_SESSIONS,
                    Permissions::REPORTS_VIEW_REVENUE,
                    Permissions::REPORTS_VIEW_CUSTOMERS,
                    Permissions::REPORTS_VIEW_NETWORK,
                ],
            ],
        ];
    }

    /**
     * Roles an ISP may hand out to its own operators — everything except the platform role.
     *
     * @return array<int, string>
     */
    public static function assignableByTenant(): array
    {
        return array_values(array_diff(
            array_keys(self::definitions()),
            [self::PLATFORM_SUPER_ADMIN],
        ));
    }
}
