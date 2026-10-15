<!DOCTYPE html>
<html>
<body style="margin:0;padding:24px;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#334155;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr><td align="center">
            <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;">
                <tr><td style="padding:28px 28px 8px;">
                    <p style="margin:0;font-size:12px;font-weight:bold;letter-spacing:2px;text-transform:uppercase;color:#0b58ad;">{{ $tenant->name }}</p>
                    <h1 style="margin:12px 0 0;font-size:22px;color:#0f172a;">
                        {{ $paid ? __('Thank you for your payment') : __('Your internet bill is ready') }}
                    </h1>
                </td></tr>
                <tr><td style="padding:12px 28px;font-size:14px;line-height:22px;">
                    <p style="margin:0 0 12px;">{{ __('Hi :name,', ['name' => strtok((string) $invoice->customer?->name, ' ') ?: __('there')]) }}</p>
                    @if ($paid)
                        <p style="margin:0 0 12px;">{{ __('We received :amount for :description. Your receipt :number is attached.', ['amount' => $invoice->currency.' '.number_format((float) $invoice->amount, 0), 'description' => $invoice->description, 'number' => $invoice->number]) }}</p>
                    @else
                        <p style="margin:0 0 12px;">{{ __('Invoice :number for :amount is due on :date (:description). It is attached as a PDF.', ['number' => $invoice->number, 'amount' => $invoice->currency.' '.number_format((float) $invoice->amount, 0), 'date' => $invoice->due_at->format('j M Y'), 'description' => $invoice->description]) }}</p>
                    @endif
                </td></tr>
                @unless ($paid)
                    <tr><td style="padding:4px 28px 16px;">
                        <a href="{{ $payUrl }}" style="display:inline-block;background:#0b58ad;color:#ffffff;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:10px;">{{ __('Pay by M-PESA') }}</a>
                    </td></tr>
                @endunless
                <tr><td style="padding:4px 28px 28px;font-size:13px;color:#64748b;">
                    <a href="{{ $viewUrl }}" style="color:#0b58ad;">{{ $paid ? __('View the receipt online') : __('View the invoice online') }}</a>
                    @if ($tenant->contact_phone)
                        · {{ __('Questions? Call :phone', ['phone' => $tenant->contact_phone]) }}
                    @endif
                </td></tr>
            </table>
            <p style="margin:16px 0 0;font-size:11px;color:#94a3b8;">{{ __('Billing by :platform', ['platform' => config('app.name')]) }}</p>
        </td></tr>
    </table>
</body>
</html>
