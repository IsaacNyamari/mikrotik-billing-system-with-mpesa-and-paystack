<?php

use App\Enums\PaymentStatus;
use App\Models\MpesaConfiguration;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Router;
use App\Services\Mpesa\PaymentCollector;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The page a customer lands on when they join the wifi.
 *
 * They pick a package, pay from their phone, and are logged in to the HotSpot without ever
 * seeing a username. This runs unauthenticated: the portal key in the URL is what says which
 * router — and therefore which ISP — they came from.
 */
new #[Layout('components.layouts.portal')] #[Title('Get online')] class extends Component {
    #[Locked]
    public string $portalKey = '';

    #[Locked]
    public int $routerId = 0;

    #[Locked]
    public ?int $paymentId = null;

    public ?int $selectedPlanId = null;

    public string $phone = '';

    /** Where the MikroTik wants credentials posted, taken from its redirect. */
    #[Locked]
    public string $loginUrl = '';

    #[Locked]
    public string $clientMac = '';

    #[Locked]
    public string $clientIp = '';

    public function mount(string $portalKey): void
    {
        // Public route: no tenant is resolved yet, so the lookup deliberately spans every ISP
        // and then binds the one this router belongs to.
        $router = Tenancy::withoutScope(
            fn (): ?Router => Router::query()->withoutGlobalScopes()->where('portal_key', $portalKey)->first(),
        );

        abort_if($router === null, 404);
        abort_unless($router->tenant->isOperational(), 403, __('This service is not available.'));

        Tenancy::set($router->tenant);

        $this->portalKey = $portalKey;
        $this->routerId = $router->id;

        // MikroTik passes these on its redirect to us.
        $this->loginUrl = (string) request()->query('link-login-only', '');
        $this->clientMac = (string) request()->query('mac', '');
        $this->clientIp = (string) request()->query('ip', request()->ip());
    }

    /**
     * Rebind the tenant on every Livewire round trip: the middleware cannot do it here
     * because nobody is signed in.
     */
    public function hydrate(): void
    {
        if ($this->routerId !== 0 && ! Tenancy::has()) {
            $router = Tenancy::withoutScope(
                fn (): ?Router => Router::query()->withoutGlobalScopes()->find($this->routerId),
            );

            if ($router !== null) {
                Tenancy::set($router->tenant);
            }
        }
    }

    #[Computed]
    public function router(): Router
    {
        return Router::query()->findOrFail($this->routerId);
    }

    #[Computed]
    public function tenant(): \App\Models\Tenant
    {
        return $this->router->tenant;
    }

    /**
     * @return Collection<int, Plan>
     */
    #[Computed]
    public function plans(): Collection
    {
        return Plan::query()
            ->ofType(\App\Enums\PlanType::Hotspot)
            ->sellable()
            ->orderBy('sort_order')
            ->orderBy('price')
            ->get();
    }

    #[Computed]
    public function payment(): ?Payment
    {
        return $this->paymentId === null
            ? null
            : Payment::query()->with('hotspotUser')->find($this->paymentId);
    }

    /**
     * Whether this ISP can take money right now.
     *
     * An ISP that has not finished setting up M-PESA is an ordinary state, not an error, so
     * the portal says so plainly rather than offering a button that cannot work.
     */
    #[Computed]
    public function canTakePayment(): bool
    {
        return MpesaConfiguration::query()->where('is_active', true)->exists();
    }

    public function pay(PaymentCollector $collector): void
    {
        $validated = $this->validate([
            'selectedPlanId' => ['required', Rule::exists('plans', 'id')->where('tenant_id', Tenancy::id())],
            // Kenyan mobile numbers, however the customer chooses to type them.
            'phone' => ['required', 'string', 'regex:/^(?:\+?254|0)?7\d{8}$|^(?:\+?254|0)?1\d{8}$/'],
        ], [
            'phone.regex' => __('Enter a Safaricom number like 07XX XXX XXX.'),
            'selectedPlanId.required' => __('Choose a package first.'),
        ]);

        $plan = Plan::query()->findOrFail($validated['selectedPlanId']);

        try {
            $payment = $collector->collect($plan, $this->router, $validated['phone'], [
                'client_mac' => $this->clientMac !== '' ? $this->clientMac : null,
                'client_ip' => $this->clientIp !== '' ? $this->clientIp : null,
            ]);
        } catch (\Throwable $e) {
            // A customer standing in a shop should never be shown a stack trace. The detail
            // goes to the log for the ISP; they get something they can act on.
            Log::error('Portal payment could not be started', [
                'tenant_id' => Tenancy::id(),
                'plan_id' => $plan->id,
                'error' => $e->getMessage(),
            ]);

            $this->addError('payment', __('Payments are not available right now. Please ask at the counter.'));

            return;
        }

        $this->paymentId = $payment->id;
    }

    /**
     * Called by wire:poll while the customer is on their phone.
     */
    public function checkPayment(): void
    {
        unset($this->payment);

        $payment = $this->payment;

        if ($payment === null || $payment->status !== PaymentStatus::Pending) {
            return;
        }

        // Safaricom's prompt expires after about a minute. Waiting forever would leave the
        // customer staring at a spinner.
        if ($payment->created_at->addMinutes((int) config('mpesa.abandon_after_minutes'))->isPast()) {
            // Assigned rather than mass-updated: status is set by the payment flow, not by
            // input, so it is deliberately not fillable and update() would drop it.
            $payment->status = PaymentStatus::Abandoned;
            $payment->save();

            unset($this->payment);
        }
    }

    public function startOver(): void
    {
        $this->reset(['paymentId', 'selectedPlanId', 'phone']);
        unset($this->payment);
    }

    /**
     * Whether we are still waiting on the customer's phone.
     */
    #[Computed]
    public function isWaiting(): bool
    {
        return $this->payment?->status === PaymentStatus::Pending;
    }
}; ?>

<div class="mx-auto flex min-h-screen w-full max-w-lg flex-col gap-6 p-4 sm:p-6">
    <header class="pt-4 text-center">
        <h1 class="text-2xl font-semibold text-zinc-900 dark:text-white">{{ $this->tenant->name }}</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Choose a package to get online') }}</p>
    </header>

    @if ($this->payment?->status === App\Enums\PaymentStatus::Paid && $this->payment->hotspotUser)
        @php($access = $this->payment->hotspotUser)

        <div class="rounded-xl border border-green-200 bg-green-50 p-6 text-center dark:border-green-900 dark:bg-green-950">
            <flux:icon.check-circle class="mx-auto size-12 text-green-600 dark:text-green-400" />
            <h2 class="mt-3 text-lg font-semibold text-green-900 dark:text-green-100">{{ __('Payment received') }}</h2>
            <p class="mt-1 text-sm text-green-800 dark:text-green-200">
                {{ __('Your code is your username and password. Keep it to log back in.') }}
            </p>

            <p class="mt-4 font-mono text-2xl font-bold tracking-widest text-green-900 dark:text-green-100">
                {{ $access->username }}
            </p>

            @if ($access->expires_at)
                <p class="mt-2 text-xs text-green-700 dark:text-green-300">
                    {{ __('Valid for :time', ['time' => $access->remainingForHumans()]) }}
                </p>
            @endif
        </div>

        @if ($loginUrl !== '')
            {{-- Posted straight to the MikroTik, which is what actually lets them online.
                 The HotSpot profile accepts http-pap, so the credentials go as plain fields. --}}
            <form method="post" action="{{ $loginUrl }}" id="hotspot-login">
                <input type="hidden" name="username" value="{{ $access->username }}">
                <input type="hidden" name="password" value="{{ $access->password }}">
                <flux:button type="submit" variant="primary" class="w-full">
                    {{ __('Connect now') }}
                </flux:button>
            </form>

            <script>
                // Log them in without making them press anything.
                setTimeout(() => document.getElementById('hotspot-login')?.submit(), 1200);
            </script>
        @else
            <div class="rounded-lg bg-amber-50 p-4 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200">
                {{ __('Now return to the wifi login page and sign in with the code above.') }}
            </div>
        @endif
    @elseif ($this->isWaiting)
        <div
            class="rounded-xl border border-zinc-200 bg-white p-6 text-center dark:border-zinc-700 dark:bg-zinc-900"
            wire:poll.3s="checkPayment"
        >
            <flux:icon.device-phone-mobile class="mx-auto size-12 animate-pulse text-zinc-400" />
            <h2 class="mt-3 text-lg font-semibold text-zinc-900 dark:text-white">{{ __('Check your phone') }}</h2>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                {{ __('Enter your M-PESA PIN to pay :amount.', ['amount' => number_format((float) $this->payment->amount, 2)]) }}
            </p>
            <p class="mt-4 text-xs text-zinc-500">{{ __('This page updates by itself.') }}</p>
        </div>
    @elseif ($this->payment && $this->payment->status->isSettled())
        <div class="rounded-xl border border-red-200 bg-red-50 p-6 text-center dark:border-red-900 dark:bg-red-950">
            <flux:icon.x-circle class="mx-auto size-12 text-red-600 dark:text-red-400" />
            <h2 class="mt-3 text-lg font-semibold text-red-900 dark:text-red-100">
                {{ $this->payment->status->label() }}
            </h2>
            <p class="mt-1 text-sm text-red-800 dark:text-red-200">
                {{ $this->payment->result_description ?? __('The payment did not go through.') }}
            </p>
            <flux:button class="mt-4 w-full" wire:click="startOver">{{ __('Try again') }}</flux:button>
        </div>
    @else
        @unless ($this->canTakePayment)
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-center dark:border-amber-900 dark:bg-amber-950">
                <p class="font-medium text-amber-900 dark:text-amber-100">{{ __('Payments are not set up yet') }}</p>
                <p class="mt-1 text-sm text-amber-800 dark:text-amber-200">
                    {{ __('Please ask at the counter for access.') }}
                </p>
            </div>
        @endunless

        <form wire:submit="pay" class="flex flex-col gap-3">
            @forelse ($this->plans as $plan)
                @php($selected = $selectedPlanId === $plan->id)

                <label
                    wire:key="plan-{{ $plan->id }}"
                    @class([
                        'relative flex cursor-pointer items-center gap-4 rounded-xl border bg-white p-4 shadow-sm transition dark:bg-zinc-900',
                        'border-blue-600 ring-2 ring-blue-600/20 dark:border-blue-400' => $selected,
                        'border-zinc-200 hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600' => ! $selected,
                    ])
                >
                    <input
                        type="radio"
                        wire:model.live="selectedPlanId"
                        value="{{ $plan->id }}"
                        class="size-5 shrink-0 border-zinc-300 text-blue-600 focus:ring-blue-600 dark:border-zinc-600"
                    >

                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-zinc-900 dark:text-white">{{ $plan->name }}</p>
                        <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">
                            {{ $plan->validityForHumans() }} &middot; {{ $plan->speedForHumans() }}
                        </p>
                        @if ($plan->data_cap_mb)
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                {{ number_format($plan->data_cap_mb) }} MB {{ __('included') }}
                            </p>
                        @endif
                    </div>

                    <div class="shrink-0 text-right">
                        <span class="text-xl font-bold tabular-nums text-zinc-900 dark:text-white">
                            {{ number_format((float) $plan->price, 0) }}
                        </span>
                        <span class="block text-xs text-zinc-400">{{ $this->tenant->currency }}</span>
                    </div>
                </label>
            @empty
                <div class="rounded-xl border border-zinc-200 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="text-zinc-600 dark:text-zinc-400">{{ __('No packages are on sale right now.') }}</p>
                </div>
            @endforelse

            @error('selectedPlanId')
                <p class="text-sm font-medium text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror

            @if ($this->plans->isNotEmpty() && $this->canTakePayment)
                <div class="mt-2">
                    <label for="portal-phone" class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        {{ __('M-PESA number') }}
                    </label>
                    <input
                        id="portal-phone"
                        wire:model="phone"
                        type="tel"
                        inputmode="numeric"
                        autocomplete="tel"
                        placeholder="07XX XXX XXX"
                        required
                        @class([
                            'block w-full rounded-lg border bg-white px-3 py-2.5 text-base text-zinc-900 shadow-sm outline-none transition placeholder:text-zinc-400 focus:ring-2 dark:bg-zinc-900 dark:text-white',
                            'border-red-500 focus:border-red-500 focus:ring-red-500/20' => $errors->has('phone'),
                            'border-zinc-300 focus:border-blue-600 focus:ring-blue-600/20 dark:border-zinc-600' => ! $errors->has('phone'),
                        ])
                    >
                    @error('phone')
                        <p class="mt-1.5 text-sm font-medium text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                @error('payment')
                    <div class="rounded-lg border border-red-200 bg-red-50 p-3 dark:border-red-900 dark:bg-red-950">
                        <p class="text-sm font-medium text-red-800 dark:text-red-200">{{ $message }}</p>
                    </div>
                @enderror

                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="pay"
                    class="mt-1 flex w-full items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-3 text-base font-semibold text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-600/40 disabled:opacity-60"
                >
                    <svg wire:loading wire:target="pay" class="size-5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                    </svg>
                    <span wire:loading.remove wire:target="pay">{{ __('Pay with M-PESA') }}</span>
                    <span wire:loading wire:target="pay">{{ __('Sending request…') }}</span>
                </button>
            @endif
        </form>
    @endif

    <footer class="mt-auto pb-4 text-center text-xs text-zinc-400">
        @if ($this->tenant->contact_phone)
            {{ __('Support: :phone', ['phone' => $this->tenant->contact_phone]) }}
        @endif
    </footer>
</div>
