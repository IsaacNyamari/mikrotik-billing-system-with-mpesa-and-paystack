{{-- Rendered by dompdf, which knows CSS 2.1 and little else: tables for layout, no flex or
     grid, no Tailwind, and DejaVu Sans so every character in a name prints. --}}
@php
    $paid = $invoice->status === App\Enums\InvoiceStatus::Paid;
    $void = $invoice->status === App\Enums\InvoiceStatus::Void;
    $money = fn ($amount) => $invoice->currency.' '.number_format((float) $amount, 2);
    [$stampText, $stampColor] = match ($invoice->status) {
        App\Enums\InvoiceStatus::Paid => [__('PAID'), '#0ca30c'],
        App\Enums\InvoiceStatus::Overdue => [__('OVERDUE'), '#d03b3b'],
        App\Enums\InvoiceStatus::Void => [__('CANCELLED'), '#64748b'],
        default => [__('DUE'), '#d97706'],
    };
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $paid ? __('Receipt') : __('Invoice') }} {{ $invoice->number }}</title>
    <style>
        @page { margin: 36px 40px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #334155; }
        h1 { font-size: 22px; margin: 0; color: #0f172a; letter-spacing: 1px; }
        .muted { color: #64748b; }
        .isp { font-size: 16px; font-weight: bold; color: #0f172a; }
        .label { font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; color: #94a3b8; }
        .stamp { display: inline-block; margin-top: 8px; padding: 4px 10px; border: 2px solid {{ $stampColor }}; color: {{ $stampColor }}; font-weight: bold; letter-spacing: 2px; }
        table { width: 100%; border-collapse: collapse; }
        .rule { border-bottom: 1px solid #e2e8f0; }
        .lines th { text-align: left; padding: 8px 0; border-bottom: 1px solid #e2e8f0; }
        .lines td { padding: 12px 0; border-bottom: 1px solid #f1f5f9; }
        .right, .lines th.right { text-align: right; }
        .total { font-size: 16px; font-weight: bold; color: #0f172a; }
        .note { margin-top: 24px; padding: 12px; background: #f8fafc; }
        .foot { margin-top: 32px; text-align: center; font-size: 9px; color: #94a3b8; }
    </style>
</head>
<body>
    <table class="rule">
        <tr>
            <td style="vertical-align: top; padding-bottom: 16px;">
                <div class="isp">{{ $tenant->name }}</div>
                <div class="muted">
                    @if ($tenant->address){{ $tenant->address }}<br>@endif
                    @if ($tenant->contact_phone){{ $tenant->contact_phone }}<br>@endif
                    @if ($tenant->contact_email){{ $tenant->contact_email }}@endif
                </div>
            </td>
            <td class="right" style="vertical-align: top; padding-bottom: 16px;">
                <h1>{{ $paid ? __('RECEIPT') : __('INVOICE') }}</h1>
                <div class="muted">{{ $invoice->number }}</div>
                <div class="stamp">{{ $stampText }}</div>
            </td>
        </tr>
    </table>

    <table class="rule" style="margin-top: 16px;">
        <tr>
            <td style="vertical-align: top; padding-bottom: 16px; width: 55%;">
                <div class="label">{{ __('Bill to') }}</div>
                <div style="margin-top: 6px; font-weight: bold; color: #0f172a;">{{ $invoice->customer?->name }}</div>
                <div class="muted">
                    @if ($invoice->customer?->phone){{ $invoice->customer->phone }}<br>@endif
                    @if ($invoice->customer?->email){{ $invoice->customer->email }}<br>@endif
                    @if ($account->installation_address){{ $account->installation_address }}<br>@endif
                    {{ __('Account :number', ['number' => $account->account_number]) }}
                </div>
            </td>
            <td style="vertical-align: top; padding-bottom: 16px;">
                <table>
                    <tr><td class="muted">{{ __('Issued') }}</td><td class="right">{{ $invoice->created_at?->format('j M Y') }}</td></tr>
                    <tr><td class="muted">{{ __('Due') }}</td><td class="right">{{ $invoice->due_at->format('j M Y') }}</td></tr>
                    @if ($paid)
                        <tr><td class="muted">{{ __('Paid') }}</td><td class="right">{{ $invoice->paid_at?->format('j M Y, H:i') }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <table class="lines" style="margin-top: 16px;">
        <tr>
            <th class="label">{{ __('Description') }}</th>
            <th class="label right">{{ __('Amount') }}</th>
        </tr>
        <tr>
            <td>{{ $invoice->description }}</td>
            <td class="right">{{ $money($invoice->amount) }}</td>
        </tr>
        <tr>
            <td class="right total" style="border: 0; padding-right: 16px;">{{ $paid ? __('Total paid') : __('Total due') }}</td>
            <td class="right total" style="border: 0;">{{ $money($void ? 0 : $invoice->amount) }}</td>
        </tr>
    </table>

    <div class="note">
        @if ($paid)
            {{ __('Received with thanks') }}@if ($invoice->receipt) — {{ __('M-PESA reference') }} <strong>{{ $invoice->receipt }}</strong>@elseif ($invoice->payment?->provider === 'manual') — {{ __('paid in person') }}@endif.
        @elseif ($void)
            {{ __('This invoice was cancelled and nothing is owed on it.') }}
        @else
            {{ __('Pay by M-PESA from your phone at') }} {{ route('pay', ['token' => $account->pay_token]) }}.
            {{ __('Your line is reconnected or extended as soon as the payment is confirmed.') }}
        @endif
    </div>

    <div class="foot">{{ __('Billing by :platform', ['platform' => config('app.name')]) }}</div>
</body>
</html>
