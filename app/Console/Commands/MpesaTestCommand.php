<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Enums\PlanType;
use App\Models\MpesaConfiguration;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Tenant;
use App\Services\Mpesa\PaymentCollector;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\table;

/**
 * Fires a real STK push at Safaricom and reports what comes back.
 *
 * This is the only way to find out whether the credentials, the short code and the callback
 * URL actually work together — the test suite proves the logic against a fake, not the
 * account setup.
 */
class MpesaTestCommand extends Command
{
    protected $signature = 'mpesa:test
        {--phone= : Number to prompt. Defaults to Safaricom\'s sandbox test number.}
        {--plan= : Plan code to buy. Defaults to the cheapest public HotSpot plan.}
        {--tenant= : ISP slug. Required when more than one exists.}';

    protected $description = 'Send a real STK push to check the M-PESA setup end to end';

    public function handle(PaymentCollector $collector): int
    {
        $tenant = $this->tenant();

        if ($tenant === null) {
            return self::FAILURE;
        }

        return Tenancy::forTenant($tenant, function () use ($collector): int {
            $configuration = MpesaConfiguration::query()->where('is_active', true)->first();

            if ($configuration === null) {
                $this->components->error('M-PESA is not set up for this ISP. Run mpesa:configure first.');

                return self::FAILURE;
            }

            $plan = Plan::query()
                ->ofType(PlanType::Hotspot)
                ->when($this->option('plan'), fn ($query, $code) => $query->where('code', $code))
                ->when(! $this->option('plan'), fn ($query) => $query->sellable()->orderBy('price'))
                ->first();

            $router = Router::query()->first();

            if ($plan === null || $router === null) {
                $this->components->error('Need at least one HotSpot plan and one router before testing.');

                return self::FAILURE;
            }

            $phone = (string) ($this->option('phone') ?: config('mpesa.sandbox.test_msisdn'));

            $this->components->info(sprintf(
                'Asking Safaricom (%s) to prompt %s for %s on plan "%s"…',
                $configuration->environment,
                $phone,
                number_format((float) $plan->price, 2),
                $plan->name,
            ));

            try {
                $payment = $collector->collect($plan, $router, $phone);
            } catch (Throwable $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            if ($payment->status === PaymentStatus::Failed) {
                $this->components->error('Safaricom refused the request:');
                $this->line('  '.($payment->result_description ?? 'no reason given'));
                $this->explainFailure((string) $payment->result_description);

                return self::FAILURE;
            }

            $this->components->info('Accepted. The prompt is on its way.');

            table(['Field', 'Value'], [
                ['Payment id', (string) $payment->id],
                ['CheckoutRequestID', (string) $payment->checkout_request_id],
                ['Phone', $payment->phone],
                ['Amount', (string) $payment->amount],
                ['Callback URL', $configuration->callbackUrl()],
            ]);

            $this->line('  Approve the prompt, then watch for the callback:');
            $this->line('    <options=bold>php artisan mpesa:status '.$payment->id.'</>');
            $this->newLine();
            $this->components->warn(
                'No callback means Safaricom cannot reach the URL above. It has to be public — '.
                'a tunnel such as ngrok, with APP_URL set to match.',
            );

            return self::SUCCESS;
        });
    }

    protected function explainFailure(string $message): void
    {
        $hint = match (true) {
            str_contains($message, 'Invalid Access Token'),
            str_contains($message, '400') => 'Check MPESA_CONSUMER_KEY and MPESA_CONSUMER_SECRET.',
            str_contains($message, 'Bad Request - Invalid CallBackURL') => 'Safaricom rejects http:// and localhost callbacks. Use an https tunnel.',
            str_contains($message, 'subscriber') => 'That number is not valid for this environment. Sandbox only accepts 254708374149.',
            default => null,
        };

        if ($hint !== null) {
            $this->newLine();
            $this->line('  <fg=yellow>Likely cause:</> '.$hint);
        }
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
