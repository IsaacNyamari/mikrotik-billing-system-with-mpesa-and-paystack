<?php

namespace App\Enums;

/**
 * Why a router stopped answering, as far as the router itself can tell us afterwards.
 *
 * Read from the device once it is back: its uptime says whether it restarted, and its log says
 * whether that restart was a clean one. An ISP told "power" goes to look at the socket; told
 * "connection", they ring their upstream provider.
 */
enum OutageCause: string
{
    /** Restarted without shutting down: the power went. */
    case Power = 'power';

    /** Restarted cleanly: somebody rebooted it, or it upgraded itself. */
    case Reboot = 'reboot';

    /** Never restarted: the device stayed up and the way to it failed. */
    case Connectivity = 'connectivity';

    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Power => __('Power failure'),
            self::Reboot => __('Restarted'),
            self::Connectivity => __('Lost connection'),
            self::Unknown => __('Unknown'),
        };
    }

    /**
     * How the customer is told, on the portal. Plain words, nothing to act on.
     */
    public function forCustomers(): string
    {
        return match ($this) {
            self::Power => __('a power failure'),
            self::Reboot => __('a restart of our equipment'),
            self::Connectivity => __('a loss of our internet connection'),
            self::Unknown => __('a technical problem'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Power => 'red',
            self::Reboot => 'amber',
            self::Connectivity => 'orange',
            self::Unknown => 'zinc',
        };
    }
}
