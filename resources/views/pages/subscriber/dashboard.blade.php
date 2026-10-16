<?php

use App\Enums\PaymentStatus;
use App\Enums\ServiceAccountStatus;
use App\Enums\ServiceType;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Payment;
use App\Models\ServiceAccount;
use App\Models\Tenant;
use App\Services\Subscribers\SubscriberAccess;
use App\Services\Subscribers\SubscriberConnection;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * A PPPoE subscriber's own account: what they owe, how to connect, and whether they are on.
 *
 * Nobody here is a user of the system, so no middleware binds the ISP. The session says who
 * this is (`SubscriberAccess`), and every request re-reads it — on mount and again on each
 * round trip — so a signed-out or removed subscriber is turned away on the next click.
 */
new #[Layout('components.layouts.subscriber')] #[Title('My internet account')] class extends Component {
    #[Locked]
    public int $tenantId = 0;

    #[Locked]
    public string $tenantSlug = '';

    /** Lines whose password the subscriber asked to see. */
    public array $revealed = [];

    public function mount(Tenant $tenant, SubscriberAccess $access)
    {
        $this->tenantId = $tenant->id;
        $this->tenantSlug = $tenant->slug;

        if ($access->current($tenant) === null) {
            return $this->redirectRoute('subscriber.login', ['tenant' => $tenant->slug]);
        }
    }

    public function hydrate(): void
    {
        if ($this->customer === null) {
            $this->redirectRoute('subscriber.login', ['tenant' => $this->tenantSlug]);
        }
    }

    #[Computed]
    public function tenant(): Tenant
    {
        return Tenancy::withoutScope(fn (): Tenant => Tenant::query()->findOrFail($this->tenantId));
    }

    #[Computed]
    public function customer(): ?Customer
    {
        return app(SubscriberAccess::class)->current($this->tenant);
    }

    /**
     * @return Collection<int, ServiceAccount>
     */
    #[Computed]
    public function lines(): Collection
    {
        if ($this->customer === null) {
            return collect();
        }

        return $this->customer->serviceAccounts()
            ->with(['plan', 'router'])
            ->where('type', ServiceType::Pppoe)
            ->where('status', '!=', ServiceAccountStatus::Terminated)
            ->orderBy('account_number')
            ->get();
    }

    /**
     * Live connection state per line, from each line's router.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function connections(): array
    {
        $reader = app(SubscriberConnection::class);

        return $this->lines->mapWithKeys(fn (ServiceAccount $line): array => [$line->id => $reader->status($line)])->all();
    }

    /**
     * @return Collection<int, CustomerInvoice>
     */
    public function invoicesFor(ServiceAccount $line): Collection
    {
        return CustomerInvoice::query()->where('service_account_id', $line->id)->latest('due_at')->limit(6)->get();
    }

    /**
     * @return Collection<int, Payment>
     */
    public function paymentsFor(ServiceAccount $line): Collection
    {
        return Payment::query()
            ->where('service_account_id', $line->id)
            ->where('status', PaymentStatus::Paid)
            ->latest('paid_at')
            ->limit(5)
            ->get();
    }

    public function reveal(int $lineId): void
    {
        // Only ever one of this subscriber's own lines.
        if ($this->lines->contains('id', $lineId) && ! in_array($lineId, $this->revealed, true)) {
            $this->revealed[] = $lineId;
        }
    }

    public function hide(int $lineId): void
    {
        $this->revealed = array_values(array_diff($this->revealed, [$lineId]));
    }

    public function refreshConnection(): void
    {
        $reader = app(SubscriberConnection::class);

        foreach ($this->lines as $line) {
            $reader->status($line, fresh: true);
        }

        unset($this->connections);
    }

    public function signOut(SubscriberAccess $access)
    {
        $access->signOut();

        return $this->redirectRoute('subscriber.login', ['tenant' => $this->tenantSlug]);
    }

    public static function bytes(?int $bytes): string
    {
        if ($bytes === null) {
            return '—';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return number_format($value, $i >= 3 ? 2 : 0).' '.$units[$i];
    }
}; ?>

<div class="flex flex-col gap-6" wire:poll.60s>
    <header class="flex items-start justify-between gap-4">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-600">{{ $this->tenant->name }}</p>
            <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ __('Hello, :name', ['name' => \Illuminate\Support\Str::of((string) $this->customer?->name)->before(' ')]) }}</h1>
            <p class="text-sm text-slate-500">{{ $this->customer?->customer_number }}</p>
        </div>
        <button type="button" wire:click="signOut" class="fe-btn-outline !px-4 !py-2">{{ __('Sign out') }}</button>
    </header>

    @forelse ($this->lines as $line)
        @php($days = $line->daysLeft())
        @php($payUrl = $line->payUrl())
        @php($connection = $this->connections[$line->id] ?? ['state' => 'unknown'])

        <section class="flex flex-col gap-4">
            @if ($this->lines->count() > 1)
                <h2 class="text-sm font-bold uppercase tracking-wider text-slate-500">{{ $line->label ?: $line->account_number }}</h2>
            @endif

            {{-- 3. Connection status: first, because it is the question most people open this to ask. --}}
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between gap-3">
                    <h3 class="text-sm font-bold text-slate-900">{{ __('Connection') }}</h3>
                    <button type="button" wire:click="refreshConnection" class="text-xs font-semibold text-brand-700" wire:loading.attr="disabled" wire:target="refreshConnection">
                        <span wire:loading.remove wire:target="refreshConnection">{{ __('Check again') }}</span>
                        <span wire:loading wire:target="refreshConnection">{{ __('Checking…') }}</span>
                    </button>
                </div>

                @php($state = $connection['state'])
                <div class="mt-3 flex items-center gap-3">
                    <span @class([
                        'flex size-10 shrink-0 items-center justify-center rounded-full',
                        'bg-[#0ca30c]/10' => $state === 'online',
                        'bg-[#d03b3b]/10' => in_array($state, ['offline', 'outage'], true),
                        'bg-slate-100' => $state === 'unknown',
                    ])>
                        <span @class([
                            'size-3 rounded-full',
                            'bg-[#0ca30c] shadow-[0_0_0_4px_rgba(12,163,12,.2)]' => $state === 'online',
                            'bg-[#d03b3b]' => in_array($state, ['offline', 'outage'], true),
                            'bg-slate-400' => $state === 'unknown',
                        ])></span>
                    </span>
                    <div>
                        <p class="font-bold text-slate-900">
                            @switch($state)
                                @case('online') {{ __('Online') }} @break
                                @case('outage') {{ __('Network outage in your area') }} @break
                                @case('offline') {{ __('Not connected') }} @break
                                @default {{ __('Status not available right now') }}
                            @endswitch
                        </p>
                        <p class="text-sm text-slate-500">
                            @if ($state === 'online')
                                {{ __('Connected for :uptime', ['uptime' => $connection['uptime'] ?? '—']) }}
                            @elseif ($state === 'outage')
                                {{ __('Since :time. Your provider is aware, and the time is credited to running packages.', ['time' => \Carbon\CarbonImmutable::parse($connection['outage_since'])->timezone(config('app.timezone'))->format('j M, H:i')]) }}
                            @elseif ($state === 'offline' && $line->suspended_for_nonpayment)
                                {{ __('Your line is disconnected for non-payment. Pay below and it reconnects within a minute.') }}
                            @elseif ($state === 'offline' && $line->status === App\Enums\ServiceAccountStatus::Suspended)
                                {{ __('Your line is suspended. Please contact your provider.') }}
                            @elseif ($state === 'offline')
                                {{ __('Your router is not signed in. Check it is powered on and the cable is plugged in, then restart it.') }}
                            @else
                                {{ __('We could not reach the network equipment. Try again in a minute.') }}
                            @endif
                        </p>
                    </div>
                </div>

                @if ($state === 'online')
                    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                        <div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-500">{{ __('Downloaded') }}</dt><dd class="font-semibold text-slate-900">{{ $this->bytes($connection['downloaded'] ?? null) }}</dd></div>
                        <div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-500">{{ __('Uploaded') }}</dt><dd class="font-semibold text-slate-900">{{ $this->bytes($connection['uploaded'] ?? null) }}</dd></div>
                        <div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-500">{{ __('IP address') }}</dt><dd class="font-mono text-xs font-semibold text-slate-900">{{ $connection['address'] ?? '—' }}</dd></div>
                        <div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-500">{{ __('Router MAC') }}</dt><dd class="font-mono text-xs font-semibold text-slate-900">{{ $connection['device'] ?? '—' }}</dd></div>
                    </dl>
                    <p class="mt-2 text-xs text-slate-400">{{ __('Data counts since this connection started.') }}</p>
                @endif
            </div>

            {{-- 1. Billing --}}
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="text-sm font-bold text-slate-900">{{ __('Billing') }}</h3>

                <div class="mt-3 flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p class="text-sm text-slate-500">{{ $line->plan?->name }} · {{ $line->plan?->speedForHumans() }}</p>
                        <p class="mt-1 text-lg font-bold">
                            @if ($line->suspended_for_nonpayment)
                                <span class="text-[#d03b3b]">{{ __('Disconnected — payment due') }}</span>
                            @elseif ($line->expires_at === null)
                                <span class="text-slate-900">{{ __('Not yet paid') }}</span>
                            @elseif ($days !== null && $days < 0)
                                <span class="text-[#d03b3b]">{{ __('Overdue since :date', ['date' => $line->expires_at->format('j M Y')]) }}</span>
                            @elseif ($days === 0)
                                <span class="text-amber-600">{{ __('Due today') }}</span>
                            @else
                                <span class="text-slate-900">{{ __('Paid until :date', ['date' => $line->expires_at->format('j M Y')]) }}</span>
                                <span class="block text-sm font-medium text-slate-500">{{ trans_choice(':count day left|:count days left', $days, ['count' => $days]) }}</span>
                            @endif
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-slate-500">{{ __('Monthly amount') }}</p>
                        <p class="text-2xl font-bold text-slate-900">KES {{ number_format((float) $line->plan?->price, 0) }}</p>
                    </div>
                </div>

                @if ($line->plan !== null && $line->router_id !== null)
                    <a href="{{ $payUrl }}" class="fe-btn-primary mt-4 w-full">{{ $line->suspended_for_nonpayment || ($days !== null && $days <= 3) ? __('Pay now') : __('Pay in advance') }}</a>
                @endif

                @php($invoices = $this->invoicesFor($line))
                @if ($invoices->isNotEmpty())
                    <p class="mt-5 text-xs font-bold uppercase tracking-wider text-slate-500">{{ __('Invoices & receipts') }}</p>
                    <ul class="mt-2 divide-y divide-slate-100 rounded-xl border border-slate-100">
                        @foreach ($invoices as $invoice)
                            <li class="flex items-center justify-between gap-3 px-4 py-3">
                                <span class="min-w-0">
                                    <span class="block font-mono text-sm font-semibold text-slate-900">{{ $invoice->number }}</span>
                                    <span class="block text-xs text-slate-500">{{ __('Due :date', ['date' => $invoice->due_at->format('j M Y')]) }}</span>
                                </span>
                                <span class="flex shrink-0 items-center gap-3">
                                    <span class="text-sm font-semibold text-slate-900">{{ number_format((float) $invoice->amount, 0) }}</span>
                                    <span @class([
                                        'rounded-full px-2 py-0.5 text-xs font-semibold',
                                        'bg-[#0ca30c]/10 text-[#0a7f0a]' => $invoice->status === App\Enums\InvoiceStatus::Paid,
                                        'bg-[#d03b3b]/10 text-[#b02f2f]' => $invoice->status === App\Enums\InvoiceStatus::Overdue,
                                        'bg-amber-100 text-amber-700' => $invoice->status === App\Enums\InvoiceStatus::Pending,
                                        'bg-slate-100 text-slate-500' => $invoice->status === App\Enums\InvoiceStatus::Void,
                                    ])>{{ $invoice->status->label() }}</span>
                                    <a href="{{ route('pay.invoice.pdf', ['token' => $line->pay_token, 'number' => $invoice->number]) }}" class="text-xs font-semibold text-brand-700">PDF</a>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @php($payments = $this->paymentsFor($line))
                @if ($payments->isNotEmpty())
                    <p class="mt-5 text-xs font-bold uppercase tracking-wider text-slate-500">{{ __('Recent payments') }}</p>
                    <ul class="mt-2 space-y-1.5 text-sm">
                        @foreach ($payments as $payment)
                            <li class="flex justify-between gap-3">
                                <span class="text-slate-600">{{ $payment->paid_at?->format('j M Y') }} <span class="font-mono text-xs text-slate-400">{{ $payment->receipt }}</span></span>
                                <span class="font-semibold text-slate-900">KES {{ number_format((float) $payment->amount, 0) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- 2. Credentials --}}
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="text-sm font-bold text-slate-900">{{ __('Connection details') }}</h3>
                <p class="mt-1 text-xs text-slate-500">{{ __('Enter these in your router as a PPPoE connection.') }}</p>

                <dl class="mt-4 space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">{{ __('Username') }}</dt>
                        <dd class="font-mono font-semibold text-slate-900" x-data>
                            <span>{{ $line->username }}</span>
                            <button type="button" class="ml-2 text-xs font-semibold text-brand-700" x-on:click="navigator.clipboard.writeText(@js($line->username)); $el.textContent = @js(__('Copied'))">{{ __('Copy') }}</button>
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">{{ __('Password') }}</dt>
                        <dd class="font-mono font-semibold text-slate-900" x-data>
                            @if (in_array($line->id, $revealed, true))
                                <span>{{ $line->secret }}</span>
                                <button type="button" class="ml-2 text-xs font-semibold text-brand-700" x-on:click="navigator.clipboard.writeText(@js((string) $line->secret)); $el.textContent = @js(__('Copied'))">{{ __('Copy') }}</button>
                                <button type="button" wire:click="hide({{ $line->id }})" class="ml-2 text-xs font-semibold text-slate-500">{{ __('Hide') }}</button>
                            @else
                                <span class="tracking-widest">••••••••</span>
                                <button type="button" wire:click="reveal({{ $line->id }})" class="ml-2 text-xs font-semibold text-brand-700">{{ __('Show') }}</button>
                            @endif
                        </dd>
                    </div>
                    @if ($service = data_get($line->router?->setup_options, 'pppoe_service_name'))
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">{{ __('Service name') }}</dt>
                            <dd class="font-mono text-slate-900">{{ $service }} <span class="font-sans text-xs text-slate-400">{{ __('(usually optional)') }}</span></dd>
                        </div>
                    @endif
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">{{ __('Account number') }}</dt>
                        <dd class="font-mono text-slate-900">{{ $line->account_number }}</dd>
                    </div>
                </dl>
            </div>
        </section>
    @empty
        <div class="rounded-2xl border border-slate-200 bg-white p-6 text-center text-sm text-slate-600 shadow-sm">
            {{ __('There is no active connection on your account. Please contact your provider.') }}
        </div>
    @endforelse

    @if ($this->tenant->contact_phone)
        <p class="text-center text-sm text-slate-500">
            {{ __('Need help? Call :isp on', ['isp' => $this->tenant->name]) }}
            <a href="tel:{{ $this->tenant->contact_phone }}" class="font-semibold text-brand-700">{{ $this->tenant->contact_phone }}</a>
        </p>
    @endif
</div>
