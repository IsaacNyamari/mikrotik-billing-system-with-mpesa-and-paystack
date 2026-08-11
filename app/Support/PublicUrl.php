<?php

namespace App\Support;

/**
 * Whether a URL is one a customer's phone could actually open.
 *
 * The captive portal's address is written onto routers — into the walled garden and into the
 * login page the device serves. A developer's machine is a perfectly good place to run the
 * platform from while testing on the same wifi, and a hopeless one to point a router in
 * somebody's shop at. The two situations look identical in the code and completely different
 * on the ground, so this is what tells them apart.
 */
class PublicUrl
{
    /**
     * Whether this address is reachable from anywhere, rather than only from one network.
     */
    public static function isReachableByCustomers(?string $url): bool
    {
        $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;

        if (! is_string($host) || $host === '') {
            return false;
        }

        if (in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
            return false;
        }

        // A hostname resolves wherever DNS says, so it is taken at face value. Only a literal
        // address can be judged from the string alone.
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return ! str_ends_with(strtolower($host), '.local');
        }

        return filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    /**
     * What to tell an operator about to write a private address onto a real router.
     */
    public static function warningFor(?string $url): ?string
    {
        if (self::isReachableByCustomers($url)) {
            return null;
        }

        return __(
            'This platform is running at :url, which only reaches devices on the same network. '.
            'A router configured with it will send customers to an address their phones cannot open. '.
            'Use it for testing on your own wifi, never for a router in the field.',
            ['url' => (string) $url],
        );
    }
}
