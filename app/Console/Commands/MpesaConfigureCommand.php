<?php

namespace App\Console\Commands;

use App\Models\MpesaConfiguration;
use App\Models\Tenant;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;

/**
 * Bootstraps one ISP's M-PESA credentials from the environment.
 *
 * This is a development and first-run convenience, not how the platform is meant to be used:
 * every ISP holds its own credentials in the database, entered through Settings → M-PESA, and
 * .env has room for exactly one set. Reach for this when standing up a sandbox; leave real
 * ISPs to enter their own, which is the only way that scales past the first tenant.
 *
 * Credentials are read from .env rather than passed as arguments so they do not end up in
 * shell history. Safaricom's published sandbox short code and passkey are used as defaults,
 * leaving only the consumer key and secret — which are specific to your Daraja app — to supply.
 */
class MpesaConfigureCommand extends Command
{
    protected $signature = 'mpesa:configure
        {--tenant= : ISP slug. Required when more than one exists.}
        {--live : Use the production Daraja endpoints instead of sandbox.}';

    protected $description = 'Bootstrap one ISP\'s M-PESA credentials from .env (ISPs normally use Settings → M-PESA)';

    public function handle(): int
    {
        $tenant = $this->tenant();

        if ($tenant === null) {
            return self::FAILURE;
        }

        $key = (string) config('mpesa.credentials.consumer_key');
        $secret = (string) config('mpesa.credentials.consumer_secret');

        if ($key === '' || $secret === '') {
            $this->components->error('Set MPESA_CONSUMER_KEY and MPESA_CONSUMER_SECRET in .env first.');
            $this->line('  Create an app at <options=bold>https://developer.safaricom.co.ke</> and copy its credentials.');

            return self::FAILURE;
        }

        $live = (bool) $this->option('live');

        return Tenancy::forTenant($tenant, function () use ($key, $secret, $live, $tenant): int {
            $configuration = MpesaConfiguration::query()->firstOrNew([]);

            $configuration->fill([
                'environment' => $live ? 'live' : 'sandbox',
                'short_code' => (string) (config('mpesa.credentials.short_code') ?: config('mpesa.sandbox.short_code')),
                'transaction_type' => 'CustomerPayBillOnline',
                'account_reference' => (string) config('mpesa.credentials.account_reference'),
                'is_active' => true,
            ]);

            $configuration->consumer_key = $key;
            $configuration->consumer_secret = $secret;
            $configuration->passkey = (string) (config('mpesa.credentials.passkey') ?: config('mpesa.sandbox.passkey'));

            $configuration->save();

            $this->components->info(sprintf(
                'M-PESA configured for %s (%s, short code %s).',
                $tenant->name,
                $configuration->environment,
                $configuration->short_code,
            ));

            $this->newLine();
            $this->line('  <options=bold>Callback URL — paste this into your Daraja app:</>');
            $this->line('  '.$configuration->callbackUrl());
            $this->newLine();

            if (str_contains($configuration->callbackUrl(), 'localhost') || str_contains($configuration->callbackUrl(), '127.0.0.1')) {
                $this->components->warn(
                    'That URL points at this machine, which Safaricom cannot reach. Start a tunnel '.
                    '(ngrok http 8000), set APP_URL to the tunnel address, and run this again.',
                );
            }

            return self::SUCCESS;
        });
    }

    protected function tenant(): ?Tenant
    {
        $slug = $this->option('tenant');

        if ($slug !== null) {
            $tenant = Tenant::query()->where('slug', $slug)->first();

            if ($tenant === null) {
                $this->components->error("No ISP with slug [{$slug}].");
            }

            return $tenant;
        }

        $tenants = Tenant::query()->limit(2)->get();

        if ($tenants->count() === 1) {
            return $tenants->first();
        }

        $this->components->error(
            $tenants->isEmpty() ? 'No ISPs exist yet.' : 'More than one ISP exists — say which with --tenant.',
        );

        return null;
    }
}
