<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Tenant;
use App\Services\Payments\PaymentReconciler;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Chases up payments nobody is watching.
 *
 * The portal asks the provider on every poll, so a customer who stays on the page is answered
 * within seconds. This is for the ones who did not: the phone locked, the browser was closed,
 * the tab was swiped away. Their money still moved, so their purchase still has to be recorded
 * and their login still has to exist — the ISP's books and the customer's next attempt both
 * depend on it.
 */
class PaymentsReconcileCommand extends Command
{
    protected $signature = 'payments:reconcile
        {--tenant= : Limit to one ISP by slug.}
        {--hours=6 : How far back to look.}';

    protected $description = 'Ask each provider what became of payments still waiting on an answer';

    public function handle(PaymentReconciler $reconciler): int
    {
        $payments = $this->pending();

        if ($payments->isEmpty()) {
            $this->components->info('Nothing waiting on an answer.');

            return self::SUCCESS;
        }

        $settled = 0;

        foreach ($payments->groupBy('tenant_id') as $tenantId => $group) {
            $tenant = Tenant::query()->withoutGlobalScopes()->find($tenantId);

            if ($tenant === null) {
                continue;
            }

            // Provisioning, gateway credentials and the payment's own scope all read the
            // current tenant, so each ISP's payments are handled as that ISP.
            Tenancy::forTenant($tenant, function () use ($group, $reconciler, &$settled): void {
                foreach ($group as $payment) {
                    $before = $payment->status;

                    $payment = $reconciler->reconcile($payment);

                    if ($payment->status !== $before) {
                        $settled++;

                        $this->components->twoColumnDetail(
                            sprintf('#%d %s %s', $payment->id, $payment->phone, $payment->amount),
                            $payment->status->label(),
                        );

                        continue;
                    }

                    $this->abandonIfStale($payment);
                }
            });
        }

        $this->components->info(sprintf('Checked %d, settled %d.', $payments->count(), $settled));

        return self::SUCCESS;
    }

    /**
     * Stop waiting on a prompt that expired long ago, so it does not get asked about forever.
     */
    protected function abandonIfStale(Payment $payment): void
    {
        if ($payment->status !== PaymentStatus::Pending) {
            return;
        }

        // Generous next to the portal's own window: the customer is no longer watching, so
        // there is nothing to be gained by giving up early, and a slow provider still gets
        // its chance to answer.
        if ($payment->created_at?->addMinutes((int) config('mpesa.abandon_after_minutes') * 5)->isFuture()) {
            return;
        }

        $payment->status = PaymentStatus::Abandoned;
        $payment->save();
    }

    /**
     * @return Collection<int, Payment>
     */
    protected function pending(): Collection
    {
        $slug = $this->option('tenant');

        return Tenancy::withoutScope(fn (): Collection => Payment::query()
            ->withoutGlobalScopes()
            ->where('status', PaymentStatus::Pending)
            // Nothing to ask about: the provider never accepted the charge, so it has no
            // record of it either.
            ->whereNotNull('checkout_request_id')
            ->where('created_at', '>=', now()->subHours((int) $this->option('hours')))
            ->when(is_string($slug) && $slug !== '', fn ($query) => $query->whereHas(
                'tenant',
                fn ($tenant) => $tenant->where('slug', $slug),
            ))
            ->orderBy('id')
            ->get());
    }
}
