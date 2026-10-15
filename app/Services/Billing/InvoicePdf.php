<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Models\CustomerInvoice;
use App\Support\Tenancy\Facades\Tenancy;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;

/**
 * A subscriber's invoice or receipt as a PDF, for download and for email.
 *
 * One template for both, so what a subscriber prints and what lands in their inbox are the same
 * document. Must run with the invoice's ISP bound: the customer and line are tenant-owned.
 */
class InvoicePdf
{
    public function make(CustomerInvoice $invoice): DomPdf
    {
        $invoice->loadMissing(['customer', 'serviceAccount', 'payment']);

        return Pdf::loadView('pdf.customer-invoice', [
            'invoice' => $invoice,
            'account' => $invoice->serviceAccount,
            'tenant' => Tenancy::tenant(),
        ])
            ->setPaper('a4')
            // Only the characters used, not the whole font: ~860 KB down to tens, which
            // matters for an attachment read on a phone.
            ->setOption('isFontSubsettingEnabled', true);
    }

    public function filename(CustomerInvoice $invoice): string
    {
        return ($invoice->status === InvoiceStatus::Paid ? 'receipt-' : 'invoice-').$invoice->number.'.pdf';
    }
}
