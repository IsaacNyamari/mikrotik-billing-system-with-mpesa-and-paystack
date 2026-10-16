<?php

use App\Models\Tenant;
use App\Services\Subscribers\SubscriberAccess;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Where a PPPoE subscriber signs in to their own account, under their ISP's address.
 *
 * A code by SMS to the number on their account, or the PPPoE login and password from their
 * router. Both are rate limited by address: this is a public page that sends texts and
 * checks passwords.
 */
new #[Layout('components.layouts.subscriber')] #[Title('Sign in to your account')] class extends Component {
    #[Locked]
    public int $tenantId = 0;

    #[Locked]
    public string $tenantSlug = '';

    /** sms or pppoe */
    public string $method = 'sms';

    /** phone, then code */
    public string $step = 'phone';

    public string $phone = '';

    public string $code = '';

    public string $username = '';

    public string $password = '';

    public ?string $notice = null;

    public function mount(Tenant $tenant, SubscriberAccess $access)
    {
        abort_unless($tenant->isOperational(), 404);

        $this->tenantId = $tenant->id;
        $this->tenantSlug = $tenant->slug;

        if ($access->current($tenant) !== null) {
            return $this->redirectRoute('subscriber.dashboard', ['tenant' => $tenant->slug], navigate: false);
        }
    }

    #[Computed]
    public function tenant(): Tenant
    {
        return Tenancy::withoutScope(fn (): Tenant => Tenant::query()->findOrFail($this->tenantId));
    }

    public function useMethod(string $method): void
    {
        $this->method = $method === 'pppoe' ? 'pppoe' : 'sms';
        $this->resetErrorBag();
        $this->notice = null;
    }

    public function sendCode(SubscriberAccess $access): void
    {
        $this->validate([
            'phone' => ['required', 'string', 'regex:/^(?:\+?254|0)?[17]\d{8}$/'],
        ], [
            'phone.regex' => __('Enter the mobile number on your account, like 07XX XXX XXX.'),
        ]);

        if ($this->throttled('subscriber-code:'.request()->ip(), 5, 600, 'phone')) {
            return;
        }

        if (! $access->sendCode($this->tenant, $this->phone)) {
            $this->method = 'pppoe';
            $this->notice = __('We could not send a text just now. Sign in with the PPPoE username and password from your router instead.');

            return;
        }

        $this->step = 'code';
        $this->notice = __('If that number is on an account, a 6-digit code is on its way. It expires in 10 minutes.');
    }

    public function verifyCode(SubscriberAccess $access)
    {
        $this->validate(['code' => ['required', 'digits:6']], ['code.digits' => __('The code is 6 digits.')]);

        if ($this->throttled('subscriber-verify:'.request()->ip(), 10, 600, 'code')) {
            return null;
        }

        $tenant = $this->tenant;
        $customer = $access->verifyCode($tenant, $this->phone, $this->code);

        if ($customer === null) {
            $this->addError('code', __('That code is not right, or it has expired.'));

            return null;
        }

        $access->signIn($tenant, $customer);

        return $this->redirectRoute('subscriber.dashboard', ['tenant' => $tenant->slug]);
    }

    public function signInWithPppoe(SubscriberAccess $access)
    {
        $this->validate([
            'username' => ['required', 'string', 'max:128'],
            'password' => ['required', 'string', 'max:128'],
        ]);

        if ($this->throttled('subscriber-pppoe:'.request()->ip(), 8, 600, 'password')) {
            return null;
        }

        $tenant = $this->tenant;
        $customer = $access->verifyPppoe($tenant, $this->username, $this->password);

        if ($customer === null) {
            $this->addError('password', __('That username and password do not match a connection.'));

            return null;
        }

        $access->signIn($tenant, $customer);

        return $this->redirectRoute('subscriber.dashboard', ['tenant' => $tenant->slug]);
    }

    public function startOver(): void
    {
        $this->reset(['step', 'code', 'notice']);
    }

    protected function throttled(string $key, int $max, int $seconds, string $field): bool
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            $this->addError($field, __('Too many attempts. Please wait a few minutes and try again.'));

            return true;
        }

        RateLimiter::hit($key, $seconds);

        return false;
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="pt-4 text-center">
        <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-600">{{ $this->tenant->name }}</p>
        <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">{{ __('Your internet account') }}</h1>
        <p class="mt-1 text-sm text-slate-500">{{ __('See your bill, your connection details and whether you are online.') }}</p>
    </div>

    <div class="mx-auto w-full max-w-md rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
        <div class="mb-5 grid grid-cols-2 gap-1 rounded-xl bg-slate-100 p-1 text-sm font-semibold">
            <button type="button" wire:click="useMethod('sms')" @class(['rounded-lg px-3 py-2 transition', 'bg-white text-brand-700 shadow-sm' => $method === 'sms', 'text-slate-500' => $method !== 'sms'])>{{ __('Code by SMS') }}</button>
            <button type="button" wire:click="useMethod('pppoe')" @class(['rounded-lg px-3 py-2 transition', 'bg-white text-brand-700 shadow-sm' => $method === 'pppoe', 'text-slate-500' => $method !== 'pppoe'])>{{ __('PPPoE login') }}</button>
        </div>

        @if ($notice)
            <p class="mb-4 rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-800">{{ $notice }}</p>
        @endif

        @if ($method === 'sms')
            @if ($step === 'phone')
                <form wire:submit="sendCode" class="flex flex-col gap-4">
                    <div>
                        <label for="phone" class="mb-1.5 block text-sm font-medium text-slate-700">{{ __('Phone number on your account') }}</label>
                        <input id="phone" type="tel" wire:model="phone" placeholder="07XX XXX XXX" class="fe-input" autocomplete="tel">
                        @error('phone') <p class="mt-1.5 text-sm font-medium text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="fe-btn-primary w-full" wire:loading.attr="disabled" wire:target="sendCode">
                        <span wire:loading.remove wire:target="sendCode">{{ __('Text me a code') }}</span>
                        <span wire:loading wire:target="sendCode">{{ __('Sending…') }}</span>
                    </button>
                </form>
            @else
                <form wire:submit="verifyCode" class="flex flex-col gap-4">
                    <div>
                        <label for="code" class="mb-1.5 block text-sm font-medium text-slate-700">{{ __('6-digit code sent to :phone', ['phone' => $phone]) }}</label>
                        <input id="code" type="text" inputmode="numeric" maxlength="6" wire:model="code" autocomplete="one-time-code" class="fe-input text-center font-mono text-lg tracking-[0.5em]">
                        @error('code') <p class="mt-1.5 text-sm font-medium text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="fe-btn-primary w-full">{{ __('Sign in') }}</button>
                    <button type="button" wire:click="startOver" class="text-sm font-semibold text-brand-700">{{ __('Use a different number') }}</button>
                </form>
            @endif
        @else
            <form wire:submit="signInWithPppoe" class="flex flex-col gap-4">
                <div>
                    <label for="username" class="mb-1.5 block text-sm font-medium text-slate-700">{{ __('PPPoE username') }}</label>
                    <input id="username" type="text" wire:model="username" autocapitalize="none" autocomplete="username" class="fe-input font-mono">
                </div>
                <div>
                    <label for="password" class="mb-1.5 block text-sm font-medium text-slate-700">{{ __('PPPoE password') }}</label>
                    <input id="password" type="password" wire:model="password" autocomplete="current-password" class="fe-input font-mono">
                    @error('password') <p class="mt-1.5 text-sm font-medium text-red-600">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="fe-btn-primary w-full">{{ __('Sign in') }}</button>
                <p class="text-center text-xs text-slate-500">{{ __('The same username and password your router uses to connect. Your provider can tell you them.') }}</p>
            </form>
        @endif
    </div>
</div>
