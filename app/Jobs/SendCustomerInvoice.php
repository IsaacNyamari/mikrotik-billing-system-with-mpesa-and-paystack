<?php

namespace App\Jobs;

use App\Mail\CustomerInvoiceMail;
use App\Models\CustomerInvoice;
use App\Models\Tenant;
use App\Services\Billing\InvoicePdf;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Emails a subscriber their invoice or receipt with the PDF attached.
 *
 * Queued, because a mail server can take seconds to answer and the billing run or the payment
 * that triggers this should not wait on it. Carries ids, not models, and binds the ISP before
 * reading anything, as every tenant-owned read needs.
 */
class SendCustomerInvoice implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(
        public int $tenantId,
        public int $invoiceId,
        public ?string $to = null,
    ) {}

    public function handle(InvoicePdf $pdf): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        Tenancy::forTenant($tenant, function () use ($tenant, $pdf): void {
            $invoice = CustomerInvoice::query()->with(['customer', 'serviceAccount', 'payment'])->find($this->invoiceId);
            $to = $this->to ?? $invoice?->customer?->email;

            if ($invoice === null || blank($to) || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
                return;
            }

            Mail::to($to)->send(new CustomerInvoiceMail(
                $invoice,
                $tenant,
                $pdf->make($invoice)->output(),
                $pdf->filename($invoice),
            ));

            $invoice->emailed_at = now();
            $invoice->save();
        });
    }

    /**
     * Queue one for this invoice, if its subscriber has an address to send it to.
     */
    public static function for(CustomerInvoice $invoice): void
    {
        $invoice->loadMissing('customer');

        if (filled($invoice->customer?->email)) {
            // After commit: raised or settled inside a transaction, the worker must find it.
            self::dispatch($invoice->tenant_id, $invoice->id)->afterCommit();
        }
    }
}
