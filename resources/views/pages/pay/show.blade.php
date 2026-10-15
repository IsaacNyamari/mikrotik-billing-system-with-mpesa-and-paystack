<?php

use App\Enums\PaymentStatus;
use App\Enums\ServiceAccountStatus;
use App\Models\Payment;
use App\Models\ServiceAccount;
use App\Services\Mpesa\CallbackProcessor;
use App\Services\Mpesa\PaymentCollector;
use App\Services\Payments\PaymentReconciler;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Where a PPPoE subscriber pays from the link in their reminder.
 *
 * Unauthenticated, like the captive portal: the token in the URL is what says which line —
 * and so which ISP — this is. Paying here goes through the ISP's own provider, so the money
 * lands where every other payment to that ISP does, and the line is renewed (and reconnected
 * if it was cut) on the verified result.
 */
new #[Layout('components.layouts.pay')] #[Title('Pay for your internet')] class extends Component {
    #[Locked]
    public int $accountId = 0;

    #[Locked]
    public int $tenantId = 0;

    #[Locked]
    public ?int $paymentId = null;

    public string $phone = '';

    public function mount(string $token): void
    {
        // Public route: no tenant is known yet, so the lookup spans every ISP and then binds
        // the one this line belongs to.
        $account = Tenancy::withoutScope(fn (): ?ServiceAccount => ServiceAccount::query()
            ->withoutGlobalScopes()
            ->where('pay_token', $token)
            ->first());

        abort_if($account === null || $account->status === ServiceAccountStatus::Terminated, 404);
        abort_unless($account->tenant()->withoutGlobalScopes()->first()?->isOperational() ?? false, 403, __('This service is not available.'));

        $tenant = $account->tenant()->withoutGlobalScopes()->firstOrFail();
        Tenancy::set($tenant);

        $this->accountId = $account->id;
        $this->tenantId = $tenant->id;
        $this->phone = (string) $account->customer?->phone;
    }

    /**
     * Rebind the tenant on every round trip: nobody is signed in, so no middleware can.
     */
    public function hydrate(): void
    {
        if ($this->tenantId !== 0 && ! Tenancy::has()) {
            $tenant = Tenancy::withoutScope(fn (): ?\App\Models\Tenant => \App\Models\Tenant::query()->find($this->tenantId));

            if ($tenant !== null) {
                Tenancy::set($tenant);
            }
        }
    }

    #[Computed]
    public function account(): ServiceAccount
    {
        return ServiceAccount::query()->with(['plan', 'customer'])->findOrFail($this->accountId);
    }

    #[Computed]
    public function payment(): ?Payment
    {
        return $this->paymentId === null ? null : Payment::query()->find($this->paymentId);
    }

    public function pay(PaymentCollector $collector): void
    {
        $validated = $this->validate([
            'phone' => ['required', 'string', 'regex:/^(?:\+?254|0)?7\d{8}$|^(?:\+?254|0)?1\d{8}$/'],
        ], [
            'phone.regex' => __('Enter a mobile number like 07XX XXX XXX.'),
        ]);

        // A public page that rings phones: capped per address so it cannot be used to pester
        // somebody with prompts.
        $key = 'pay-page:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('phone', __('Too many attempts. Please wait a minute and try again.'));

            return;
        }

        RateLimiter::hit($key, 60);

        try {
            $payment = $collector->collectRenewal($this->account, $validated['phone']);
        } catch (\Throwable $e) {
            Log::error('Renewal payment could not be started', [
                'tenant_id' => Tenancy::id(),
                'service_account_id' => $this->accountId,
                'error' => $e->getMessage(),
            ]);

            $this->addError('phone', __('Payments are not available right now. Please contact your provider.'));

            return;
        }

        $this->paymentId = $payment->id;
        unset($this->payment);
    }

    /**
     * Called by wire:poll while the subscriber is on their phone: asks the provider outright
     * rather than waiting for a webhook that may never reach this installation.
     */
    public function checkPayment(PaymentReconciler $reconciler, CallbackProcessor $processor): void
    {
        $payment = $this->payment;

        if ($payment === null) {
            return;
        }

        if ($payment->status === PaymentStatus::Paid) {
            if ($payment->applied_at === null) {
                $processor->grantAccess($payment);
            }
        } elseif ($payment->status === PaymentStatus::Pending) {
            $reconciler->reconcile($payment);

            $payment->refresh();

            if ($payment->status === PaymentStatus::Pending
                && $payment->created_at->addMinutes((int) config('mpesa.abandon_after_minutes'))->isPast()) {
                $payment->status = PaymentStatus::Abandoned;
                $payment->save();
            }
        }

        unset($this->payment, $this->account);
    }

    public function startOver(): void
    {
        $this->reset('paymentId');
        unset($this->payment);
    }
}; ?>

@php($account = $this->account)
@php($payment = $this->payment)
@php($days = $account->daysLeft())

<div class="flex flex-col gap-5">
    <div class="text-center">
        <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-600">{{ App\Support\Tenancy\Facades\Tenancy::tenant()?->name }}</p>
        <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">{{ __('Pay for your internet') }}</h1>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <dl class="space-y-3 text-sm">
            <div class="flex justify-between gap-4">
                <dt class="text-slate-500">{{ __('Account') }}</dt>
                <dd class="text-right font-semibold text-slate-900">{{ $account->customer?->name }} <span class="block text-xs font-normal text-slate-500">{{ $account->account_number }}</span></dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-slate-500">{{ __('Package') }}</dt>
                <dd class="text-right font-semibold text-slate-900">{{ $account->plan?->name }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-slate-500">{{ __('Status') }}</dt>
                <dd class="text-right font-semibold">
                    @if ($account->suspended_for_nonpayment)
                        <span class="text-[#d03b3b]">{{ __('Disconnected — pay to reconnect') }}</span>
                    @elseif ($account->expires_at === null)
                        <span class="text-slate-900">{{ __('Not yet paid') }}</span>
                    @elseif ($days !== null && $days < 0)
                        <span class="text-[#d03b3b]">{{ __('Overdue since :date', ['date' => $account->expires_at->format('j M Y')]) }}</span>
                    @else
                        <span class="text-slate-900">{{ __('Paid until :date', ['date' => $account->expires_at->format('j M Y')]) }}</span>
                    @endif
                </dd>
            </div>
            <div class="flex items-baseline justify-between gap-4 border-t border-slate-100 pt-3">
                <dt class="font-semibold text-slate-900">{{ __('Amount') }}</dt>
                <dd class="text-2xl font-bold text-slate-900">KES {{ number_format((float) $account->plan?->price, 0) }}</dd>
            </div>
        </dl>
    </div>

    @if ($payment === null)
        <form wire:submit="pay" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <label for="phone" class="mb-1.5 block text-sm font-medium text-slate-700">{{ __('Phone number to pay from') }}</label>
            <input id="phone" type="tel" wire:model="phone" placeholder="07XX XXX XXX" class="fe-input" autocomplete="tel">
            @error('phone')
                <p class="mt-1.5 text-sm font-medium text-red-600">{{ $message }}</p>
            @enderror

            <button type="submit" class="fe-btn-primary mt-4 w-full" wire:loading.attr="disabled" wire:target="pay">
                <span wire:loading.remove wire:target="pay">{{ __('Pay KES :amount', ['amount' => number_format((float) $account->plan?->price, 0)]) }}</span>
                <span wire:loading wire:target="pay">{{ __('Sending the prompt…') }}</span>
            </button>
            <p class="mt-3 text-center text-xs text-slate-500">{{ __('You will get a prompt on your phone. Enter your PIN to approve it.') }}</p>
        </form>
    @elseif ($payment->status === App\Enums\PaymentStatus::Paid)
        <div class="rounded-2xl border border-[#0ca30c]/30 bg-[#0ca30c]/5 p-5 text-center" @if ($payment->applied_at === null) wire:poll.3s="checkPayment" @endif>
            <p class="text-lg font-bold text-slate-900">{{ __('Payment received') }}</p>
            @if ($payment->applied_at !== null)
                <p class="mt-1 text-sm text-slate-600">{{ __('You are connected until :date. A confirmation is on its way by SMS.', ['date' => $account->expires_at?->format('j M Y')]) }}</p>
            @else
                <p class="mt-1 text-sm text-slate-600">{{ __('Reconnecting your line…') }}</p>
            @endif
            @if ($payment->receipt)
                <p class="mt-3 font-mono text-sm text-slate-500">{{ $payment->receipt }}</p>
            @endif
        </div>
    @elseif ($payment->status === App\Enums\PaymentStatus::Pending)
        <div class="rounded-2xl border border-slate-200 bg-white p-5 text-center shadow-sm" wire:poll.3s="checkPayment">
            <p class="text-lg font-bold text-slate-900">{{ __('Check your phone') }}</p>
            <p class="mt-1 text-sm text-slate-600">{{ __('Enter your PIN on the prompt to pay KES :amount. This page updates by itself.', ['amount' => number_format((float) $payment->amount, 0)]) }}</p>
        </div>
    @else
        <div class="rounded-2xl border border-slate-200 bg-white p-5 text-center shadow-sm">
            <p class="text-lg font-bold text-slate-900">{{ __('Not paid') }}</p>
            <p class="mt-1 text-sm text-slate-600">{{ $payment->customerMessage() }}</p>
            <button type="button" wire:click="startOver" class="fe-btn-primary mt-4 w-full">{{ __('Try again') }}</button>
        </div>
    @endif
</div>
