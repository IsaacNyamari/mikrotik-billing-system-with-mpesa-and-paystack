<?php

namespace App\Services\RouterOs\Console;

/**
 * What RouterOS values look like, for validating console forms.
 *
 * Values go over the API as separate words rather than into a script, so there is no quoting
 * to escape. These exist so that a typo is refused here, with the field named, rather than by
 * the device with a one-line trap the operator has to decode.
 */
final class Patterns
{
    /** A RouterOS object name: profiles, pools, users. */
    public const NAME = 'regex:/^[A-Za-z0-9][A-Za-z0-9@._\-]*$/';

    /** An IPv4 address with an optional prefix. */
    public const ADDRESS = 'regex:/^\d{1,3}(\.\d{1,3}){3}(\/\d{1,2})?$/';

    /** An IPv4 address with its prefix, as /ip/address requires. */
    public const CIDR = 'regex:/^\d{1,3}(\.\d{1,3}){3}\/\d{1,2}$/';

    /** One or more ranges for a pool: 10.0.0.10-10.0.0.254,10.1.0.0/24. */
    public const RANGES = 'regex:/^[0-9.\/\-]+(,[0-9.\/\-]+)*$/';

    /** A rate limit: 5M/10M, or the longer burst form, which is the same characters. */
    public const RATE = 'regex:/^[0-9kKmMgG\/ ]+$/';

    /** A RouterOS duration: 1h30m, 1d, 3600s, or plain seconds. */
    public const DURATION = 'regex:/^(\d+[wdhms])+$|^\d+$|^\d{1,2}:\d{2}:\d{2}$/';

    public const MAC = 'regex:/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/';

    /** A port or list of ports and ranges: 80, 80,443, 8000-8100. */
    public const PORTS = 'regex:/^\d{1,5}(-\d{1,5})?(,\d{1,5}(-\d{1,5})?)*$/';

    /** An interface or interface-list name, with RouterOS's leading "!" for negation. */
    public const INTERFACE = 'regex:/^!?[A-Za-z0-9][A-Za-z0-9._\-]*$/';

    /** A DNS name, with a leading wildcard where walled gardens allow one. */
    public const HOST = 'regex:/^(\*\.)?[A-Za-z0-9]([A-Za-z0-9\-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9\-]*[A-Za-z0-9])?)*$/';
}
