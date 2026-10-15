<?php

namespace App\Http\Controllers;

use App\Models\CustomerInvoice;
use App\Models\ServiceAccount;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Contracts\View\View;

/**
 * One invoice or receipt, printable, for a subscriber who has no account to sign in with.
 *
 * Reached through the line's payment token — the same one in every reminder — so knowing an
 * invoice number is not enough to read somebody else's: the number has to belong to the line
 * the token opens.
 */
class CustomerInvoiceController extends Controller
{
    public function __invoke(string $token, string $number): View
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

        return view('pay.invoice', [
            'invoice' => $invoice,
            'account' => $account,
            'tenant' => $tenant,
        ]);
    }
}
