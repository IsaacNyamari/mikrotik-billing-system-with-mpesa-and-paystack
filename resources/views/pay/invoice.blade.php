@php
    $paid = $invoice->status === App\Enums\InvoiceStatus::Paid;
    $void = $invoice->status === App\Enums\InvoiceStatus::Void;
    $money = fn ($amount) => $invoice->currency.' '.number_format((float) $amount, 2);
    $stamp = match ($invoice->status) {
        App\Enums\InvoiceStatus::Paid => ['Paid', 'border-[#0ca30c] text-[#0ca30c]'],
        App\Enums\InvoiceStatus::Overdue => ['Overdue', 'border-[#d03b3b] text-[#d03b3b]'],
        App\Enums\InvoiceStatus::Void => ['Cancelled', 'border-slate-400 text-slate-500'],
        default => ['Due', 'border-amber-500 text-amber-600'],
    };
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>{{ $paid ? __('Receipt') : __('Invoice') }} {{ $invoice->number }} — {{ $tenant->name }}</title>
        @fonts
        @vite(['resources/css/app.css'])
        <style>
            @media print {
                body { background: #fff !important; }
                .no-print { display: none !important; }
                .sheet { box-shadow: none !important; border: 0 !important; margin: 0 !important; }
            }
        </style>
    </head>
    <body class="min-h-screen bg-slate-100 font-sans text-slate-700 antialiased">
        <main class="mx-auto w-full max-w-3xl px-4 py-8">
            <div class="no-print mb-4 flex flex-wrap items-center justify-between gap-3">
                <a href="{{ route('pay', ['token' => $account->pay_token]) }}" class="text-sm font-semibold text-brand-700 hover:underline">&larr; {{ __('Back to my account') }}</a>
                <div class="flex gap-2">
                    @if ($invoice->status->isOutstanding())
                        <a href="{{ route('pay', ['token' => $account->pay_token]) }}" class="fe-btn-primary !px-4 !py-2.5">{{ __('Pay now') }}</a>
                    @endif
                    <a href="{{ route('pay.invoice.pdf', ['token' => $account->pay_token, 'number' => $invoice->number]) }}" class="fe-btn-outline !px-4 !py-2.5">{{ __('Download PDF') }}</a>
                    <button type="button" onclick="window.print()" class="fe-btn-outline !px-4 !py-2.5">{{ __('Print') }}</button>
                </div>
            </div>

            <article class="sheet rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-10">
                <header class="flex flex-wrap items-start justify-between gap-6 border-b border-slate-200 pb-6">
                    <div>
                        <p class="text-xl font-bold text-slate-900">{{ $tenant->name }}</p>
                        <div class="mt-1 space-y-0.5 text-sm text-slate-500">
                            @if ($tenant->address)<p>{{ $tenant->address }}</p>@endif
                            @if ($tenant->contact_phone)<p>{{ $tenant->contact_phone }}</p>@endif
                            @if ($tenant->contact_email)<p>{{ $tenant->contact_email }}</p>@endif
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-2xl font-bold uppercase tracking-wide text-slate-900">{{ $paid ? __('Receipt') : __('Invoice') }}</p>
                        <p class="mt-1 font-mono text-sm text-slate-600">{{ $invoice->number }}</p>
                        <span class="mt-3 inline-block -rotate-3 rounded-md border-2 px-3 py-1 text-sm font-bold uppercase tracking-widest {{ $stamp[1] }}">{{ __($stamp[0]) }}</span>
                    </div>
                </header>

                <section class="grid gap-6 border-b border-slate-200 py-6 sm:grid-cols-2">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">{{ __('Bill to') }}</p>
                        <p class="mt-2 font-semibold text-slate-900">{{ $invoice->customer?->name }}</p>
                        <div class="mt-0.5 space-y-0.5 text-sm text-slate-600">
                            @if ($invoice->customer?->phone)<p>{{ $invoice->customer->phone }}</p>@endif
                            @if ($account->installation_address)<p>{{ $account->installation_address }}</p>@endif
                            <p>{{ __('Account :number', ['number' => $account->account_number]) }}</p>
                        </div>
                    </div>
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:text-right">
                        <dt class="text-slate-500">{{ __('Issued') }}</dt>
                        <dd class="font-medium text-slate-900">{{ $invoice->created_at?->format('j M Y') }}</dd>
                        <dt class="text-slate-500">{{ __('Due') }}</dt>
                        <dd class="font-medium text-slate-900">{{ $invoice->due_at->format('j M Y') }}</dd>
                        @if ($paid)
                            <dt class="text-slate-500">{{ __('Paid') }}</dt>
                            <dd class="font-medium text-slate-900">{{ $invoice->paid_at?->format('j M Y, H:i') }}</dd>
                        @endif
                    </dl>
                </section>

                <table class="mt-6 w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-xs font-bold uppercase tracking-wider text-slate-400">
                            <th class="pb-3 pr-4 font-bold">{{ __('Description') }}</th>
                            <th class="pb-3 text-right font-bold">{{ __('Amount') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b border-slate-100">
                            <td class="py-4 pr-4 text-slate-800">{{ $invoice->description }}</td>
                            <td class="py-4 text-right font-medium text-slate-900">{{ $money($invoice->amount) }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="pt-4 pr-4 text-right font-semibold text-slate-900">{{ $paid ? __('Total paid') : __('Total due') }}</td>
                            <td class="pt-4 text-right text-xl font-bold text-slate-900">{{ $money($void ? 0 : $invoice->amount) }}</td>
                        </tr>
                    </tfoot>
                </table>

                @if ($paid)
                    <div class="mt-8 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">
                        {{ __('Received with thanks') }}@if ($invoice->receipt) — {{ __('M-PESA reference') }} <span class="font-mono font-semibold text-slate-900">{{ $invoice->receipt }}</span>@elseif ($invoice->payment?->provider === 'manual') — {{ __('paid in person') }}@endif.
                    </div>
                @elseif ($void)
                    <div class="mt-8 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">
                        {{ __('This invoice was cancelled and nothing is owed on it.') }}
                    </div>
                @else
                    <div class="mt-8 rounded-xl bg-brand-50 p-4 text-sm text-slate-700">
                        {{ __('Pay by M-PESA from your phone at') }}
                        <a href="{{ route('pay', ['token' => $account->pay_token]) }}" class="break-all font-semibold text-brand-700">{{ route('pay', ['token' => $account->pay_token]) }}</a>.
                        {{ __('Your line is reconnected or extended as soon as the payment is confirmed.') }}
                    </div>
                @endif
            </article>

            <p class="mt-6 text-center text-xs text-slate-400">{{ __('Billing by :platform', ['platform' => config('app.name')]) }}</p>
        </main>
    </body>
</html>
