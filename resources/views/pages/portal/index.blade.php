<?php

use App\Enums\HotspotUserStatus;
use App\Enums\PaymentStatus;
use App\Models\HotspotUser;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Router;
use App\Services\Mpesa\CallbackProcessor;
use App\Services\Mpesa\PaymentCollector;
use App\Services\Payments\PaymentReconciler;
use App\Services\RouterOs\HotspotUserProvisioner;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
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

    /**
     * A package the customer already owns, found by its code rather than by paying again.
     *
     * Separate from the payment because recovery starts from neither a plan nor a payment: it
     * starts from somebody holding an M-PESA SMS and no internet.
     */
    #[Locked]
    public ?int $accessId = null;

    public ?int $selectedPlanId = null;

    public string $phone = '';

    /** The M-PESA code somebody types when they have paid but never got online. */
    public string $code = '';

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

    /**
     * Where the customer's credentials are sent to sign them in.
     *
     * The HotSpot supplies `link-login-only` when it redirects, and that is the right answer
     * when it is there. But a customer who reached this page any other way — a bookmark, a
     * shared link, a redirect the phone's captive-portal helper swallowed — would otherwise be
     * shown a code and no way to use it, having already paid.
     *
     * The router's own gateway address is known here regardless, so the login URL is rebuilt
     * from it rather than left to a query parameter that may not have survived the trip.
     */
    #[Computed]
    public function signInUrl(): ?string
    {
        if ($this->loginUrl !== '') {
            return $this->loginUrl;
        }

        $gateway = $this->router->setupOptions()['hotspot_gateway'] ?? null;

        return filter_var($gateway, FILTER_VALIDATE_IP) === false ? null : 'http://'.$gateway.'/login';
    }

    /**
     * The whole link that signs this customer in, credentials and all.
     *
     * Built once here rather than twice: the page renders it as something to tap, and the
     * component sends the same string to the browser to follow by itself. Two constructions of
     * one URL is how those two quietly stop agreeing.
     */
    #[Computed]
    public function signInLink(): ?string
    {
        $access = $this->access;
        $url = $this->signInUrl;

        if ($url === null || $access === null || $access->status !== HotspotUserStatus::Active) {
            return null;
        }

        return $url.'?username='.rawurlencode($access->username).'&password='.rawurlencode($access->password);
    }

    /**
     * Tell the browser to follow the sign-in link.
     *
     * The page cannot do this by itself once it is running. The success panel arrives through a
     * polled update, which patches the DOM rather than loading it, and a `<script>` written into
     * that patch is never executed — so the automatic hop worked on a page reload and nowhere
     * else, which is precisely the case that does not happen: a customer who has just paid is
     * watching a page that is updating itself.
     *
     * A dispatched event does reach the listener the page registered when it first loaded.
     */
    protected function announceConnection(): void
    {
        if (($link = $this->signInLink) !== null) {
            $this->dispatch('portal-connect', url: $link);
        }
    }

    #[Computed]
    public function payment(): ?Payment
    {
        return $this->paymentId === null
            ? null
            : Payment::query()->with('hotspotUser')->find($this->paymentId);
    }

    /**
     * The package this customer is entitled to right now.
     *
     * Either the one they have just paid for or one they recovered with their code — the screen
     * that follows is the same, because from where they are standing the two are the same thing:
     * they have paid, and they want to be online.
     */
    #[Computed]
    public function access(): ?HotspotUser
    {
        if ($this->accessId !== null) {
            return HotspotUser::query()->find($this->accessId);
        }

        return $this->payment?->hotspotUser;
    }

    /**
     * Whether this ISP can take money right now.
     *
     * Asked of the gateway manager rather than of M-PESA directly: an ISP collecting through
     * Paystack has no Daraja row at all, and checking for one would tell its customers that
     * payments are not set up while they demonstrably are. An ISP that has finished setting up
     * neither is an ordinary state, not an error, so the portal says so plainly rather than
     * offering a button that cannot work.
     */
    #[Computed]
    public function canTakePayment(): bool
    {
        return app(\App\Services\Payments\PaymentGatewayManager::class)->active() !== null;
    }

    /**
     * The package the customer tapped.
     */
    #[Computed]
    public function selectedPlan(): ?Plan
    {
        return $this->selectedPlanId === null ? null : $this->plans->firstWhere('id', $this->selectedPlanId);
    }

    /**
     * Tapping a package's button.
     *
     * Choosing and paying are two taps rather than one form: a customer holding a phone in one
     * hand should be asked for exactly one thing at a time, and the number is worth asking for
     * only once they have decided what they are buying.
     */
    public function choose(int $planId): void
    {
        // Read from the same list the page offered, so an id typed into the wire cannot buy a
        // package this ISP has withdrawn or never sold.
        if ($this->plans->firstWhere('id', $planId) === null) {
            return;
        }

        $this->selectedPlanId = $planId;
        $this->resetErrorBag();

        unset($this->selectedPlan);
    }

    /**
     * Back to the packages, having thought better of it.
     */
    public function changePlan(): void
    {
        $this->reset(['selectedPlanId']);
        $this->resetErrorBag();

        unset($this->selectedPlan);
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
     *
     * Asks the provider outright rather than hoping a webhook turns up. The webhook is the
     * provider reaching us, which needs this installation to be reachable from the internet;
     * this is us reaching the provider, which works from a laptop on a shop's wifi. A customer
     * holding the M-PESA confirmation SMS should never be looking at a spinner.
     */
    public function checkPayment(PaymentReconciler $reconciler, CallbackProcessor $processor): void
    {
        unset($this->payment);

        $payment = $this->payment;

        if ($payment === null) {
            return;
        }

        // Paid, but the access was never issued — the router was unreachable when the money
        // landed. The money has moved, so keep trying rather than making them pay twice.
        if ($payment->status === PaymentStatus::Paid) {
            if ($payment->hotspot_user_id === null) {
                $processor->grantAccess($payment);
            }

            unset($this->payment, $this->access, $this->signInLink);

            // Written to the router but refused there — the device was down at that moment.
            // Keep pushing rather than leaving somebody who has paid holding a code the router
            // has never heard of.
            $access = $this->access;

            if ($access !== null && $access->status !== HotspotUserStatus::Active && ! $access->hasExpired()) {
                app(HotspotUserProvisioner::class)->provision($access);

                unset($this->access, $this->signInLink);
            }

            $this->announceConnection();

            return;
        }

        if ($payment->status !== PaymentStatus::Pending) {
            return;
        }

        $reconciler->reconcile($payment);

        unset($this->payment, $this->access);
        $payment = $this->payment;

        if ($payment === null || $payment->status !== PaymentStatus::Pending) {
            return;
        }

        // The prompt expires after about a minute. Waiting forever would leave the customer
        // staring at a spinner for something that is never coming.
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
        $this->reset(['paymentId', 'accessId', 'selectedPlanId', 'phone', 'code']);

        unset($this->payment, $this->selectedPlan, $this->access, $this->signInLink);
    }

    /**
     * Get somebody back online from their M-PESA code alone.
     *
     * This is the way out of every gap between the money moving and the customer being on the
     * internet, and there are more of those than there ought to be: the router was unreachable
     * when the callback landed, the phone hopped to mobile data for the M-PESA prompt and never
     * came back, the browser was closed, the package was bought at the counter, the tab was lost
     * behind the captive-portal helper. In every one of them the customer has an SMS with a code
     * in it and no idea which of those things happened — nor should they need one.
     *
     * So the code is the whole of what is asked for, and everything behind it is tried in turn:
     * a login that already exists, a payment that was confirmed but never issued one, and a
     * payment the provider has not been asked about yet. Only when none of those finds anything
     * is the customer told the code is unknown.
     */
    public function recover(
        PaymentReconciler $reconciler,
        CallbackProcessor $processor,
        HotspotUserProvisioner $provisioner,
    ): void {
        // Tidied before it is validated, not after. Somebody copying a code out of an SMS
        // brings a trailing space with it and reads the characters off in whatever case they
        // see them, and a page that answers that with a complaint about punctuation is a page
        // arguing with a customer who has done nothing wrong.
        $this->code = strtoupper(trim($this->code));

        $this->validate([
            // M-PESA receipts are ten characters; codes issued at a counter are the same shape.
            // The bound is loose on purpose — a customer mistyping is told so by the lookup, in
            // plain words, rather than by a validator quibbling about length.
            'code' => ['required', 'string', 'min:6', 'max:32', 'regex:/^[A-Z0-9]+$/'],
        ], [
            'code.required' => __('Enter the code from your M-PESA message.'),
            'code.regex' => __('A code is only letters and numbers, like QGR4TZ8LMN.'),
            'code.min' => __('That code looks too short. Check your M-PESA message.'),
        ]);

        // Codes are ten characters from an alphabet of thirty-odd, so guessing one is not a
        // realistic attack. The limit is here because an unmetered lookup on a public page is
        // still a public page doing unmetered database work.
        $key = 'portal-recover:'.$this->routerId.':'.(request()->ip() ?? 'unknown');

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->addError('code', __('Too many tries. Wait a minute, then try again.'));

            return;
        }

        RateLimiter::hit($key, 60);

        $code = $this->code;

        // Scoped to this router, not just this ISP: the login only exists on the device the
        // customer is standing in front of, so a code from another branch would be found here
        // and then fail to let them on — which is worse than not finding it.
        $access = HotspotUser::query()
            ->where('router_id', $this->routerId)
            ->where('username', $code)
            ->latest('id')
            ->first();

        if ($access === null) {
            $access = $this->accessFromPayment($code, $reconciler, $processor);

            // A payment was found and had something to say about itself — an error is already
            // on the field and it is more use than "code not found".
            if ($access === null && $this->getErrorBag()->has('code')) {
                return;
            }
        }

        if ($access === null) {
            $this->addError('code', __('We could not find that code on this network. Check the code in your M-PESA message, or ask at the counter.'));

            return;
        }

        if ($access->hasExpired() || $access->status->isFinished()) {
            $this->addError('code', __('That package has already been used up. Buy another one to get back online.'));

            return;
        }

        // Pushed to the router again whenever it is not known to be live there. The provisioner
        // updates a row it finds rather than adding a second, so doing this for somebody who was
        // already fine costs one round trip and changes nothing.
        if ($access->status !== HotspotUserStatus::Active || $access->routeros_id === null) {
            $access = $provisioner->provision($access);
        }

        $this->accessId = $access->id;

        $this->reset(['code']);
        $this->resetErrorBag();

        unset($this->access, $this->payment, $this->signInLink);

        $this->announceConnection();
    }

    /**
     * The access a payment should have bought, chasing it up if it has not yet.
     *
     * Adds an error and returns null when the payment is findable but is not access — a
     * customer whose prompt was cancelled is owed that answer rather than "code not found",
     * which would send them looking for a typo that is not there.
     */
    protected function accessFromPayment(string $code, PaymentReconciler $reconciler, CallbackProcessor $processor): ?HotspotUser
    {
        $payment = Payment::query()
            ->where('router_id', $this->routerId)
            ->where('receipt', $code)
            ->latest('id')
            ->first();

        if ($payment === null) {
            return null;
        }

        // Still open: ask the provider outright rather than telling somebody holding a
        // confirmation SMS that nothing has been paid.
        if ($payment->status === PaymentStatus::Pending) {
            $reconciler->reconcile($payment);

            $payment->refresh();
        }

        if ($payment->status === PaymentStatus::Pending) {
            $this->addError('code', __('That payment is still being confirmed. Give it a moment and try again.'));

            return null;
        }

        if ($payment->status !== PaymentStatus::Paid) {
            $this->addError('code', $payment->customerMessage());

            return null;
        }

        // Paid, but no login was ever issued — the router was unreachable when the money
        // landed. This is the case the whole screen exists for.
        $payment = $processor->grantAccess($payment);

        return $payment->hotspot_user_id === null
            ? null
            : HotspotUser::query()->find($payment->hotspot_user_id);
    }

    /**
     * Called by wire:poll while a recovered package is still being written to the router.
     */
    public function retryAccess(HotspotUserProvisioner $provisioner): void
    {
        unset($this->access, $this->signInLink);

        $access = $this->access;

        if ($access === null) {
            return;
        }

        if ($access->status === HotspotUserStatus::Active) {
            $this->announceConnection();

            return;
        }

        if ($access->hasExpired() || $access->status->isFinished()) {
            return;
        }

        $provisioner->provision($access);

        unset($this->access, $this->signInLink);

        $this->announceConnection();
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

    {{-- One screen for "you have paid and here is your access", whether the payment happened
         thirty seconds ago or yesterday and the customer typed their code to get back. From
         where they are standing those are the same situation. --}}
    @if ($this->access)
        @php($access = $this->access)
        @php($live = $access->status === App\Enums\HotspotUserStatus::Active)

        {{-- Polled a little slower than the payment screen: each pass is a real attempt to reach
             a router that has already refused once, and hammering a device that is down helps
             nobody. hotspot:sweep --retry is trying the same thing in the background. --}}
        <div class="note note--good" @if (! $live) wire:poll.5s="retryAccess" @endif>
            <svg @class(['icon', 'pulse' => ! $live]) viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 4.5-5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <h2 class="note__title">{{ __('Payment received') }}</h2>
            <p class="note__text">
                {{ $live ? $this->branding->displaySuccessText() : __('Setting up your access…') }}
            </p>

            <p class="code">{{ $access->username }}</p>

            @if ($live && $access->expires_at)
                <p class="note__text">{{ __('Valid for :time', ['time' => $access->remainingForHumans()]) }}</p>
            @endif

            @unless ($live)
                {{-- The code is shown regardless, because it is theirs and the SMS has it
                     anyway. What is withheld until the router confirms is the Connect button:
                     sending somebody to a login the device has never heard of hands them a
                     refusal for something that is about to work. --}}
                <p class="note__text">{{ __('Keep this page open. This takes a moment.') }}</p>
            @endunless
        </div>

        @if ($this->signInLink)
            {{-- Posted straight to the MikroTik, which is what actually lets them online. The
                 HotSpot profile accepts http-pap, so the credentials go as plain fields.

                 Submitted the moment it renders, with nothing for the customer to press: they
                 have paid, and being asked to confirm that they would like what they just bought
                 is the kind of step that strands people on a page. --}}
            {{-- A plain link, not a form.

                 This page is served over https and the router's login sits on plain http at
                 an address inside the network. A form posting across that boundary is what
                 browsers call an insecure form submission, and Chrome refuses it — usually
                 without saying anything at all, which is exactly what a customer who has just
                 paid experiences as "nothing happened". A top-level navigation is not subject
                 to that rule, so the credentials go in the query string instead: RouterOS
                 accepts them there for http-pap, which is what this profile is set up for.

                 The link is visible rather than hidden. If the phone has drifted onto mobile
                 data while paying — which is common, since the M-PESA prompt arrives over the
                 cellular network — the router is unreachable and the automatic hop fails
                 silently. Something they can see and tap after rejoining the wifi is the
                 difference between a working purchase and a refund. --}}
            <a href="{{ $this->signInLink }}" class="btn" id="hotspot-login" rel="noreferrer">
                {{ __('Connect now') }}
            </a>

            <p class="note__text" style="margin-top:.75rem">
                {{ __('Not connected? Rejoin the wifi, then tap Connect now.') }}
            </p>

            {{-- Only for a customer who arrives with their access already live — a reload, or
                 a code recovered on the way in. The polled case cannot be handled here: this
                 markup is patched into a running page, and a script patched in is never run.
                 That is what the listener at the bottom of the page is for. --}}
            <script>window.portalConnect && window.portalConnect(@js($this->signInLink));</script>
        @elseif ($live)
            <div class="note note--warn">
                <p class="note__text">{{ __('Now return to the wifi login page and sign in with the code above.') }}</p>
            </div>
        @endif
    @elseif ($this->payment?->status === App\Enums\PaymentStatus::Paid)
        {{-- The money arrived but the login has not been created yet, which means the router
             was unreachable at that moment. Keep polling: checkPayment retries the issue, and
             hotspot:sweep --retry is doing the same in the background. --}}
        <div class="note note--good" wire:poll.3s="checkPayment">
            <svg class="icon pulse" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 4.5-5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <h2 class="note__title">{{ __('Payment received') }}</h2>
            <p class="note__text">{{ __('Setting up your access…') }}</p>
            @if ($this->payment->receipt)
                <p class="code">{{ $this->payment->receipt }}</p>
            @endif
        </div>
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
            @if ($this->payment->status === App\Enums\PaymentStatus::Cancelled)
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <circle cx="12" cy="12" r="9"/><path d="M8 12h8" stroke-linecap="round"/>
                </svg>
                <h2 class="note__title">{{ __('Payment cancelled') }}</h2>
            @else
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <circle cx="12" cy="12" r="9"/><path d="m9 9 6 6m0-6-6 6" stroke-linecap="round"/>
                </svg>
                <h2 class="note__title">{{ $this->payment->status->label() }}</h2>
            @endif

            <p class="note__text">{{ $this->payment->customerMessage() }}</p>
            <button type="button" class="btn btn--quiet" style="margin-top:1rem" wire:click="startOver">
                {{ __('Try again') }}
            </button>

        </div>

        {{-- Offered here for the one case that looks like this and is not: money that did leave
             the customer's account while the platform was told otherwise. They have the SMS in
             their hand, and the code in it is enough. --}}
        @include('portal.recover', ['heading' => __('Charged anyway?')])
    @elseif ($this->selectedPlan)
        {{-- Second tap: they have chosen, so now — and only now — they are asked for a number. --}}
        @php($plan = $this->selectedPlan)

        <div class="card">
            <div class="chosen">
                <div>
                    <p class="chosen__name" style="margin:0">{{ $plan->name }}</p>
                    <p class="chosen__meta" style="margin:.125rem 0 0">
                        {{ $plan->validityForHumans() }}@if ($this->branding->show_speed) &middot; {{ $plan->speedForHumans() }}@endif
                    </p>
                    <button type="button" class="link" style="margin-top:.375rem" wire:click="changePlan">
                        {{ __('Change package') }}
                    </button>
                </div>

                <p class="chosen__price" style="margin:0">
                    <small style="font-size:.75rem;font-weight:600;opacity:.7">{{ $this->branding->displayCurrency($this->tenant) }}</small>
                    {{ number_format((float) $plan->price, 0) }}
                </p>
            </div>

            <form wire:submit="pay">
                <div class="field">
                    <label class="field__label" for="portal-phone">{{ __('M-PESA number') }}</label>
                    <input
                        id="portal-phone"
                        wire:model="phone"
                        type="tel"
                        inputmode="numeric"
                        autocomplete="tel"
                        placeholder="07XX XXX XXX"
                        autofocus
                        required
                        @class(['field__input', 'field__input--bad' => $errors->has('phone')])
                    >
                    @error('phone')
                        <p class="field__error">{{ $message }}</p>
                    @enderror
                    @error('selectedPlanId')
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
                    <span wire:loading.remove wire:target="pay">
                        {{ __('Pay :currency :amount', [
                            'currency' => $this->branding->displayCurrency($this->tenant),
                            'amount' => number_format((float) $plan->price, 0),
                        ]) }}
                    </span>
                    <span wire:loading wire:target="pay">{{ __('Sending request…') }}</span>
                </button>
            </form>

            <p class="chosen__meta" style="margin:.75rem 0 0;text-align:center">
                {{ __('A prompt will come to this number. Enter your PIN to pay.') }}
            </p>
        </div>
    @else
        @if ($this->branding->show_banner && $this->plans->isNotEmpty())
            <div class="banner">
                <svg class="banner__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path d="M2 8.5a15 15 0 0 1 20 0M5 12a10 10 0 0 1 14 0M8.5 15.5a5 5 0 0 1 7 0" stroke-linecap="round"/>
                    <circle cx="12" cy="19" r="1" fill="currentColor" stroke="none"/>
                </svg>
                <span>{{ $this->branding->displayBannerText($this->tenant) }}</span>
            </div>
        @endif

        @unless ($this->canTakePayment)
            <div class="note note--warn">
                <h2 class="note__title">{{ __('Payments are not set up yet') }}</h2>
                <p class="note__text">{{ __('Please ask at the counter for access.') }}</p>
            </div>
        @endunless

        @if ($this->plans->isEmpty())
            <div class="note note--plain">
                <p class="note__text">{{ __('No packages are on sale right now.') }}</p>
            </div>
        @else
            <div @class(['deals', 'deals--list' => ! $this->branding->usesGrid()])>
                @foreach ($this->plans as $plan)
                    <div
                        wire:key="plan-{{ $plan->id }}"
                        @class(['deal', 'deal--tinted' => $this->branding->isTinted(), 'deal--plain' => ! $this->branding->isTinted()])
                    >
                        <span class="deal__badge">{{ $plan->validityForHumans() }}</span>

                        <div class="deal__body">
                            <p class="deal__price">
                                <small>{{ $this->branding->displayCurrency($this->tenant) }}</small>{{ number_format((float) $plan->price, 0) }}
                            </p>

                            <p class="deal__meta">{{ $plan->name }}</p>

                            @if ($plan->description)
                                <p class="deal__meta">{{ $plan->description }}</p>
                            @endif

                            @if ($this->branding->show_speed)
                                <p class="deal__meta">{{ $plan->speedForHumans() }}</p>
                            @endif

                            @if ($plan->data_cap_mb && $this->branding->show_data_cap)
                                <p class="deal__meta">{{ number_format($plan->data_cap_mb) }} MB {{ __('included') }}</p>
                            @endif
                        </div>

                        {{-- One tap chooses; the number is asked for on the next screen. --}}
                        <button
                            type="button"
                            class="deal__cta"
                            wire:click="choose({{ $plan->id }})"
                            wire:loading.attr="disabled"
                            @disabled(! $this->canTakePayment)
                        >
                            {{ $this->branding->displayBuyLabel() }}
                        </button>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Under the packages rather than beside them: almost everyone here is buying, and the
             few who are not are looking for exactly this. It is offered whether or not packages
             are on sale — an ISP whose plans are all withdrawn still has customers holding codes
             they paid for. --}}
        @include('portal.recover', ['heading' => __('Already paid?')])
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

    {{-- Registered once, when the page first loads and long before anybody has paid.

         This is the whole reason the automatic sign-in works at all. The success panel arrives
         through a polled update, which patches the DOM rather than reloading it, and a script
         written into that patch is never executed — so the hop that was supposed to happen
         "with nothing to press" only ever fired on a page reload, which is not what a customer
         who has just paid is doing. They watched a page that had their code on it and did
         nothing, which is indistinguishable from the purchase having failed.

         @script is evaluated by Livewire on the component's own initialisation, so the listener
         is in place before it is needed and survives every re-render after. --}}
    @script
    <script>
        (function () {
            let going = false;

            // Named on window so the panel's own inline script can reach it too, for the
            // customer who arrives with their access already live — a reload, or a code
            // recovered on the way in.
            window.portalConnect = function (url) {
                if (going || ! url) {
                    return;
                }

                going = true;

                // Given a moment first: the router has only just been told about this login,
                // and a device that answers before it knows the code shows the customer a
                // failure for something that is about to work.
                setTimeout(function () {
                    window.location.href = url;
                }, 800);
            };

            $wire.on('portal-connect', function (event) {
                window.portalConnect(Array.isArray(event) ? event[0]?.url : event?.url);
            });
        })();
    </script>
    @endscript
</div>
