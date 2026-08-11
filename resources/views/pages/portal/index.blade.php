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
     * This ISP's branding. Always returns a usable object, so a portal renders even for an
     * ISP that has customised nothing.
     */
    #[Computed]
    public function branding(): \App\Models\PortalSetting
    {
        return \App\Models\PortalSetting::current();
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

<div class="portal">
    <header class="portal__head">
        @if ($this->branding->logoUrl())
            <img src="{{ $this->branding->logoUrl() }}" alt="{{ $this->branding->displayName($this->tenant) }}" class="portal__logo">
        @endif

        <h1 class="portal__title">{{ $this->branding->displayName($this->tenant) }}</h1>
        <p class="portal__sub">{{ $this->branding->displayHeadline() }}</p>

        @if ($this->branding->intro_text)
            <p class="portal__sub">{{ $this->branding->intro_text }}</p>
        @endif
    </header>

    @if ($this->payment?->status === App\Enums\PaymentStatus::Paid && $this->payment->hotspotUser)
        @php($access = $this->payment->hotspotUser)

        <div class="note note--good">
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 4.5-5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <h2 class="note__title">{{ __('Payment received') }}</h2>
            <p class="note__text">{{ $this->branding->displaySuccessText() }}</p>

            <p class="code">{{ $access->username }}</p>

            @if ($access->expires_at)
                <p class="note__text">{{ __('Valid for :time', ['time' => $access->remainingForHumans()]) }}</p>
            @endif
        </div>

        @if ($loginUrl !== '')
            {{-- Posted straight to the MikroTik, which is what actually lets them online. The
                 HotSpot profile accepts http-pap, so the credentials go as plain fields. --}}
            <form method="post" action="{{ $loginUrl }}" id="hotspot-login">
                <input type="hidden" name="username" value="{{ $access->username }}">
                <input type="hidden" name="password" value="{{ $access->password }}">
                <button type="submit" class="btn">{{ __('Connect now') }}</button>
            </form>

            <script>
                // Log them in without making them press anything. The button stays as the
                // fallback for a browser that blocks this.
                setTimeout(function () {
                    var form = document.getElementById('hotspot-login');
                    if (form) { form.submit(); }
                }, 1200);
            </script>
        @else
            <div class="note note--warn">
                <p class="note__text">{{ __('Now return to the wifi login page and sign in with the code above.') }}</p>
            </div>
        @endif
    @elseif ($this->isWaiting)
        <div class="note note--plain" wire:poll.3s="checkPayment">
            <svg class="icon pulse" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <rect x="7" y="2.5" width="10" height="19" rx="2"/><path d="M11 18.5h2" stroke-linecap="round"/>
            </svg>
            <h2 class="note__title">{{ __('Check your phone') }}</h2>
            <p class="note__text">
                {{ __('Enter your M-PESA PIN to pay :amount.', ['amount' => number_format((float) $this->payment->amount, 2)]) }}
            </p>
            <p class="note__text">{{ __('This page updates by itself.') }}</p>
        </div>
    @elseif ($this->payment && $this->payment->status->isSettled())
        <div class="note note--bad">
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <circle cx="12" cy="12" r="9"/><path d="m9 9 6 6m0-6-6 6" stroke-linecap="round"/>
            </svg>
            <h2 class="note__title">{{ $this->payment->status->label() }}</h2>
            <p class="note__text">{{ $this->payment->result_description ?? __('The payment did not go through.') }}</p>
            <button type="button" class="btn btn--quiet" style="margin-top:1rem" wire:click="startOver">
                {{ __('Try again') }}
            </button>
        </div>
    @else
        @unless ($this->canTakePayment)
            <div class="note note--warn">
                <h2 class="note__title">{{ __('Payments are not set up yet') }}</h2>
                <p class="note__text">{{ __('Please ask at the counter for access.') }}</p>
            </div>
        @endunless

        <form wire:submit="pay">
            <fieldset class="plans">
                <legend class="sr-only">{{ __('Packages') }}</legend>

                @forelse ($this->plans as $plan)
                    <label wire:key="plan-{{ $plan->id }}" @class(['plan', 'plan--on' => $selectedPlanId === $plan->id])>
                        <input type="radio" wire:model.live="selectedPlanId" value="{{ $plan->id }}">

                        <span class="plan__body">
                            <span class="plan__name">{{ $plan->name }}</span>
                            <span class="plan__meta" style="display:block">
                                {{ $plan->validityForHumans() }}@if ($this->branding->show_speed) &middot; {{ $plan->speedForHumans() }}@endif
                            </span>
                            @if ($plan->data_cap_mb && $this->branding->show_data_cap)
                                <span class="plan__meta" style="display:block">
                                    {{ number_format($plan->data_cap_mb) }} MB {{ __('included') }}
                                </span>
                            @endif
                        </span>

                        <span class="plan__price">
                            <span class="plan__amount">{{ number_format((float) $plan->price, 0) }}</span>
                            <span class="plan__currency">{{ $this->tenant->currency }}</span>
                        </span>
                    </label>
                @empty
                    <div class="note note--plain">
                        <p class="note__text">{{ __('No packages are on sale right now.') }}</p>
                    </div>
                @endforelse
            </fieldset>

            @error('selectedPlanId')
                <p class="field__error" style="margin-top:.75rem">{{ $message }}</p>
            @enderror

            @if ($this->plans->isNotEmpty() && $this->canTakePayment)
                <div class="field" style="margin-top:1.25rem">
                    <label class="field__label" for="portal-phone">{{ __('M-PESA number') }}</label>
                    <input
                        id="portal-phone"
                        wire:model="phone"
                        type="tel"
                        inputmode="numeric"
                        autocomplete="tel"
                        placeholder="07XX XXX XXX"
                        required
                        @class(['field__input', 'field__input--bad' => $errors->has('phone')])
                    >
                    @error('phone')
                        <p class="field__error">{{ $message }}</p>
                    @enderror
                </div>

                @error('payment')
                    <div class="note note--bad" style="margin-top:1rem;text-align:left">
                        <p class="note__text">{{ $message }}</p>
                    </div>
                @enderror

                <button type="submit" class="btn" style="margin-top:1rem" wire:loading.attr="disabled" wire:target="pay">
                    <svg wire:loading wire:target="pay" class="spinner" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" opacity=".25"/>
                        <path fill="currentColor" opacity=".75" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                    </svg>
                    <span wire:loading.remove wire:target="pay">{{ __('Pay with M-PESA') }}</span>
                    <span wire:loading wire:target="pay">{{ __('Sending request…') }}</span>
                </button>
            @endif
        </form>
    @endif

    <footer class="portal__foot">
        @if ($this->branding->footer_text)
            <p style="margin:0 0 .25rem">{{ $this->branding->footer_text }}</p>
        @endif

        @if ($this->branding->displaySupportPhone($this->tenant))
            <p style="margin:0">
                {{ __('Support: :phone', ['phone' => $this->branding->displaySupportPhone($this->tenant)]) }}
            </p>
        @endif

        @if ($this->branding->terms_url)
            <p style="margin:.25rem 0 0">
                <a href="{{ $this->branding->terms_url }}" rel="noopener noreferrer" target="_blank">{{ __('Terms of service') }}</a>
            </p>
        @endif
    </footer>
</div>
