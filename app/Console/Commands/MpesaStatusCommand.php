<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\MpesaConfiguration;
use App\Models\Payment;
use App\Models\Tenant;
use App\Services\Mpesa\CallbackProcessor;
use App\Services\Mpesa\Contracts\MpesaGateway;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;

use function Laravel\Prompts\table;

/**
 * Reports what became of a payment, and can ask Safaricom directly.
 *
 * Useful when the callback never arrived — which locally usually means the tunnel was down
 * rather than that the customer failed to pay.
 */
class MpesaStatusCommand extends Command
{
    protected $signature = 'mpesa:status
        {payment : Payment id}
        {--ask : Query Safaricom rather than trusting what we already hold.}';

    protected $description = 'Show a payment and optionally ask Safaricom what happened to it';

    public function handle(MpesaGateway $gateway, CallbackProcessor $processor): int
    {
        $payment = Tenancy::withoutScope(
            fn (): ?Payment => Payment::query()->withoutGlobalScopes()->find($this->argument('payment')),
        );

        if ($payment === null) {
            $this->components->error('No payment with that id.');

            return self::FAILURE;
        }

        $tenant = Tenant::query()->whereKey($payment->tenant_id)->firstOrFail();

        return Tenancy::forTenant($tenant, function () use ($payment, $gateway, $processor): int {
            $payment->refresh()->loadMissing(['plan', 'hotspotUser']);

            $access = $payment->hotspotUser;

            table(['Field', 'Value'], [
                ['Status', $payment->status->label()],
                ['Phone', $payment->phone],
                ['Amount', (string) $payment->amount],
                ['Receipt', $payment->receipt ?? '—'],
                ['Result', $payment->result_description ?? '—'],
                ['Paid at', (string) ($payment->paid_at ?? '—')],
                ['Access issued', $access === null ? '—' : $access->username],
                ['Access status', $access === null ? '—' : $access->status->label()],
            ]);

            if ($payment->status === PaymentStatus::Paid && $access === null) {
                $this->components->warn('Paid, but no access was issued. Run: php artisan hotspot:sweep --retry');
            }

            if (! $this->option('ask')) {
                if ($payment->status === PaymentStatus::Pending) {
                    $this->line('  Still waiting on a callback. Re-run with <options=bold>--ask</> to query Safaricom.');
                }

                return self::SUCCESS;
            }

            $configuration = MpesaConfiguration::query()->where('is_active', true)->first();

            if ($configuration === null || $payment->checkout_request_id === null) {
                $this->components->error('Nothing to query — no configuration or no CheckoutRequestID.');

                return self::FAILURE;
            }

            $this->components->info('Asking Safaricom…');

            $result = $gateway->query($configuration, $payment->checkout_request_id);

            if ($result === []) {
                $this->components->error('No answer from Safaricom.');

                return self::FAILURE;
            }

            $this->line('  '.json_encode($result, JSON_PRETTY_PRINT));

            // A query result carries the same ResultCode a callback would, so a payment whose
            // callback never arrived can be settled from here.
            if (isset($result['ResultCode']) && $payment->status === PaymentStatus::Pending) {
                $processor->process([
                    'CheckoutRequestID' => $payment->checkout_request_id,
                    'ResultCode' => (int) $result['ResultCode'],
                    'ResultDesc' => $result['ResultDesc'] ?? null,
                ]);

                $this->newLine();
                $this->components->info('Settled from the query. Re-run without --ask to see the result.');
            }

            return self::SUCCESS;
        });
    }
}
