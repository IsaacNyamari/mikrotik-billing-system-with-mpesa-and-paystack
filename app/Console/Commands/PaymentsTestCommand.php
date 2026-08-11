<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Enums\PlanType;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Tenant;
use App\Services\Mpesa\PaymentCollector;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\table;

/**
 * Sends a real charge through whichever provider the ISP has chosen, and prints exactly what
 * came back.
 *
 * The portal deliberately shows a customer nothing but "ask at the counter", so the provider's
 * own words are otherwise only in the log. This puts them on screen, which is the difference
 * between diagnosing a setup in one go and guessing at it.
 */
class PaymentsTestCommand extends Command
{
    protected $signature = 'payments:test
        {--phone= : Number to prompt. Defaults to the provider\'s test number.}
        {--plan= : Plan code to buy. Defaults to the cheapest public HotSpot plan.}
        {--tenant= : ISP slug. Required when more than one exists.}';

    protected $description = 'Send a real charge through the active provider and report the answer';

    public function handle(PaymentCollector $collector, PaymentGatewayManager $gateways): int
    {
        $tenant = $this->tenant();

        if ($tenant === null) {
            return self::FAILURE;
        }

        return Tenancy::forTenant($tenant, function () use ($collector, $gateways): int {
            $gateway = $gateways->active();

            if ($gateway === null) {
                $this->components->error('This ISP has no payment provider set up. See Settings → Payments.');

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
                'Charging %s through %s for %s on "%s" …',
                $phone,
                $gateway->provider()->label(),
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
                $this->components->error('The provider refused the charge:');
                $this->newLine();
                // Verbatim, because a paraphrase is what made this hard to diagnose.
                $this->line('  <fg=red>'.($payment->result_description ?? 'no reason given').'</>');
                $this->newLine();
                $this->explain((string) $payment->result_description);

                return self::FAILURE;
            }

            $this->components->info('Accepted. The prompt is on its way.');

            table(['Field', 'Value'], [
                ['Payment id', (string) $payment->id],
                ['Provider', $gateway->provider()->label()],
                ['Reference', (string) $payment->checkout_request_id],
                ['Phone', $payment->phone],
                ['Amount', (string) $payment->amount],
            ]);

            $this->line('  Approve it, then check what happened:');
            $this->line('    <options=bold>php artisan mpesa:status '.$payment->id.'</>');
            $this->newLine();
            $this->components->warn(
                'No confirmation means the provider cannot reach this server. Its webhook has to '.
                'be a public URL, so APP_URL must point at a tunnel rather than a LAN address.',
            );

            return self::SUCCESS;
        });
    }

    /**
     * Turn a provider's terser refusals into the thing that is actually wrong.
     */
    protected function explain(string $message): void
    {
        $hint = match (true) {
            str_contains($message, 'invalid email'),
            str_contains($message, 'billing email') => 'Set a billing email under Settings → Payments.',
            str_contains($message, 'phone') => 'Paystack wants +2547XXXXXXXX; Daraja wants 2547XXXXXXXX with no plus.',
            str_contains($message, 'Invalid key'),
            str_contains($message, 'Invalid Access Token') => 'Check the API credentials for this provider.',
            str_contains($message, 'test mobile money') => 'Test keys only charge the Paystack test number — find it in their dashboard, or switch to live keys.',
            str_contains($message, 'not supported'),
            str_contains($message, 'mobile money') => 'Mobile money may not be enabled on your Paystack account for KES — check Preferences in their dashboard.',
            str_contains($message, 'CallBackURL') => 'The callback URL must be https and publicly reachable.',
            default => null,
        };

        if ($hint !== null) {
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
