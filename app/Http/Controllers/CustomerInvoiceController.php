<?php

namespace App\Http\Controllers;

use App\Models\CustomerInvoice;
use App\Models\ServiceAccount;
use App\Models\Tenant;
use App\Services\Billing\InvoicePdf;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;

/**
 * One invoice or receipt, printable, for a subscriber who has no account to sign in with.
 *
 * Reached through the line's payment token — the same one in every reminder — so knowing an
 * invoice number is not enough to read somebody else's: the number has to belong to the line
 * the token opens.
 */
class CustomerInvoiceController extends Controller
{
    public function show(string $token, string $number): View
    {
        [$invoice, $account, $tenant] = $this->resolve($token, $number);

        return view('pay.invoice', [
            'invoice' => $invoice,
            'account' => $account,
            'tenant' => $tenant,
        ]);
    }

    /**
     * The same invoice as a PDF download.
     */
    public function pdf(string $token, string $number, InvoicePdf $pdf): Response
    {
        [$invoice] = $this->resolve($token, $number);

        return $pdf->make($invoice)->download($pdf->filename($invoice));
    }

    /**
     * @return array{0: CustomerInvoice, 1: ServiceAccount, 2: Tenant}
     */
    protected function resolve(string $token, string $number): array
    {
        $account = Tenancy::withoutScope(fn (): ?ServiceAccount => ServiceAccount::query()
            ->withoutGlobalScopes()
            ->where('pay_token', $token)
            ->first());

        abort_if($account === null, 404);

        $tenant = $account->tenant()->firstOrFail();
        Tenancy::set($tenant);

        $invoice = CustomerInvoice::query()
            ->where('service_account_id', $account->id)
            ->where('number', $number)
            ->with(['customer', 'payment'])
            ->firstOrFail();

        return [$invoice, $account, $tenant];
    }
}
