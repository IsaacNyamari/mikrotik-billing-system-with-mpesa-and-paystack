<?php

namespace App\Mail;

use App\Enums\InvoiceStatus;
use App\Models\CustomerInvoice;
use App\Models\Tenant;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * An invoice, or once paid its receipt, to the subscriber, with the PDF attached.
 *
 * Built and sent inside `SendCustomerInvoice`, with the ISP bound, rather than queued itself: a
 * queued mailable re-reads its models in the worker with no tenant known, which strict scoping
 * refuses. It goes out under the ISP's name with replies to the ISP, because the subscriber's
 * relationship is with them.
 */
class CustomerInvoiceMail extends Mailable
{
    public function __construct(
        public CustomerInvoice $invoice,
        public Tenant $tenant,
        public string $pdf,
        public string $filename,
    ) {}

    public function envelope(): Envelope
    {
        $paid = $this->invoice->status === InvoiceStatus::Paid;

        return new Envelope(
            from: new Address((string) config('mail.from.address'), $this->tenant->name),
            replyTo: filled($this->tenant->contact_email) ? [new Address((string) $this->tenant->contact_email, $this->tenant->name)] : [],
            subject: $paid
                ? __('Receipt :number from :isp', ['number' => $this->invoice->number, 'isp' => $this->tenant->name])
                : __('Invoice :number from :isp — due :date', ['number' => $this->invoice->number, 'isp' => $this->tenant->name, 'date' => $this->invoice->due_at->format('j M Y')]),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.customer-invoice', with: [
            'invoice' => $this->invoice,
            'tenant' => $this->tenant,
            'paid' => $this->invoice->status === InvoiceStatus::Paid,
            'payUrl' => $this->invoice->serviceAccount->payUrl(),
            'viewUrl' => $this->invoice->publicUrl(),
        ]);
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => $this->pdf, $this->filename)->withMime('application/pdf'),
        ];
    }
}
