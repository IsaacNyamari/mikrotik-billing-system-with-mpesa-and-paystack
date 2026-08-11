<?php

use App\Models\ContactEnquiry;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('components.layouts.frontend')] #[Title('Contact us')] class extends Component {
    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $company = '';

    public string $routerCount = '';

    public string $message = '';

    /**
     * A field no person will ever fill in, and every naive bot will.
     *
     * Cheaper and less insulting to a real customer than a puzzle: the ISP filling this in is
     * on a phone, and a captcha is a reason to give up.
     */
    public string $website = '';

    public bool $sent = false;

    public function submit(): void
    {
        // Silently accepted and dropped. Telling a bot it was caught only teaches whoever
        // wrote it to try something else.
        if ($this->website !== '') {
            $this->sent = true;

            return;
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180'],
            'phone' => ['nullable', 'string', 'max:32'],
            'company' => ['nullable', 'string', 'max:120'],
            'routerCount' => ['nullable', 'string', 'max:32'],
            'message' => ['required', 'string', 'min:10', 'max:2000'],
        ], [
            'message.min' => __('Tell us a little more so we can answer properly.'),
        ]);

        // Public and unauthenticated, so it is rate limited by address. Generous enough that
        // an office behind one connection can send several.
        $key = 'contact:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('message', __('You have sent several messages already. Give us a moment to reply to those first.'));

            return;
        }

        RateLimiter::hit($key, 3600);

        ContactEnquiry::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?: null,
            'company' => $validated['company'] ?: null,
            'router_count' => $validated['routerCount'] ?: null,
            'message' => $validated['message'],
            'ip_address' => request()->ip(),
        ]);

        $this->sent = true;
    }

    public function sendAnother(): void
    {
        $this->reset(['name', 'email', 'phone', 'company', 'routerCount', 'message', 'sent']);
    }
}; ?>

<div>
    <x-frontend.hero
        compact
        :eyebrow="__('Contact')"
        :heading="__('Let\'s get you selling')"
        :lead="__('Tell us how many routers you run and what you sell. We will come back with what setting you up looks like.')"
    />

    <section class="px-6 pb-20">
        <div class="mx-auto grid max-w-5xl gap-10 lg:grid-cols-[1fr_20rem]">
            {{-- The form ------------------------------------------------------------- --}}
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 sm:p-8 dark:border-zinc-800 dark:bg-zinc-900">
                @if ($sent)
                    <div class="py-8 text-center">
                        <span class="mx-auto flex size-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">
                            <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="m5 13 4 4L19 7" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </span>

                        <h2 class="mt-4 text-xl font-semibold text-zinc-900 dark:text-white">{{ __('Thanks — we have it') }}</h2>
                        <p class="mx-auto mt-2 max-w-sm text-sm text-zinc-600 dark:text-zinc-400">
                            {{ __('We usually reply within a working day. If it is urgent, call the number on this page.') }}
                        </p>

                        <button type="button" wire:click="sendAnother" class="mt-6 text-sm font-medium text-zinc-900 underline underline-offset-4 dark:text-white">
                            {{ __('Send another message') }}
                        </button>
                    </div>
                @else
                    <form wire:submit="submit" class="flex flex-col gap-5">
                        {{-- Left where a bot will find it and a person will not. --}}
                        <div class="hidden" aria-hidden="true">
                            <label for="website">{{ __('Website') }}</label>
                            <input id="website" type="text" wire:model="website" tabindex="-1" autocomplete="off">
                        </div>

                        <div class="grid gap-5 sm:grid-cols-2">
                            <x-frontend.field name="name" :label="__('Your name')" required>
                                <input id="name" type="text" wire:model="name" required
                                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-zinc-900 focus:ring-2 focus:ring-zinc-900/10 dark:border-zinc-700 dark:bg-zinc-950 dark:focus:border-white">
                            </x-frontend.field>

                            <x-frontend.field name="email" :label="__('Email')" required>
                                <input id="email" type="email" wire:model="email" required
                                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-zinc-900 focus:ring-2 focus:ring-zinc-900/10 dark:border-zinc-700 dark:bg-zinc-950 dark:focus:border-white">
                            </x-frontend.field>

                            <x-frontend.field name="phone" :label="__('Phone')">
                                <input id="phone" type="tel" wire:model="phone" placeholder="07XX XXX XXX"
                                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-zinc-900 focus:ring-2 focus:ring-zinc-900/10 dark:border-zinc-700 dark:bg-zinc-950 dark:focus:border-white">
                            </x-frontend.field>

                            <x-frontend.field name="company" :label="__('Business name')">
                                <input id="company" type="text" wire:model="company"
                                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-zinc-900 focus:ring-2 focus:ring-zinc-900/10 dark:border-zinc-700 dark:bg-zinc-950 dark:focus:border-white">
                            </x-frontend.field>
                        </div>

                        <x-frontend.field name="routerCount" :label="__('How many routers do you run?')">
                            <select id="routerCount" wire:model="routerCount"
                                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-zinc-900 focus:ring-2 focus:ring-zinc-900/10 dark:border-zinc-700 dark:bg-zinc-950 dark:focus:border-white">
                                <option value="">{{ __('Select one') }}</option>
                                <option value="1">{{ __('Just one') }}</option>
                                <option value="2-5">{{ __('Two to five') }}</option>
                                <option value="6-20">{{ __('Six to twenty') }}</option>
                                <option value="20+">{{ __('More than twenty') }}</option>
                            </select>
                        </x-frontend.field>

                        <x-frontend.field name="message" :label="__('What do you need?')" required>
                            <textarea id="message" wire:model="message" rows="5" required
                                placeholder="{{ __('We run a HotSpot in Kawangware and about 30 PPPoE homes…') }}"
                                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-zinc-900 focus:ring-2 focus:ring-zinc-900/10 dark:border-zinc-700 dark:bg-zinc-950 dark:focus:border-white"></textarea>
                        </x-frontend.field>

                        <button type="submit" wire:loading.attr="disabled" wire:target="submit"
                            class="inline-flex items-center justify-center gap-2 rounded-xl bg-zinc-900 px-6 py-3 text-sm font-semibold text-white hover:bg-zinc-700 disabled:opacity-60 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">
                            <span wire:loading.remove wire:target="submit">{{ __('Send message') }}</span>
                            <span wire:loading wire:target="submit">{{ __('Sending…') }}</span>
                        </button>
                    </form>
                @endif
            </div>

            {{-- The other ways to reach us ------------------------------------------- --}}
            <aside class="flex flex-col gap-4">
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
                    <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Talk to a person') }}</h2>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                        {{ __('A form is fine for a quote. If your portal is down, call.') }}
                    </p>

                    <dl class="mt-4 space-y-3 text-sm">
                        <div>
                            <dt class="text-zinc-500">{{ __('Email') }}</dt>
                            <dd><a href="mailto:hello@solforbs.com" class="font-medium text-zinc-900 hover:underline dark:text-white">hello@solforbs.com</a></dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500">{{ __('Phone') }}</dt>
                            <dd><a href="tel:+254759900802" class="font-medium text-zinc-900 hover:underline dark:text-white">+254 759 900 802</a></dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500">{{ __('Hours') }}</dt>
                            <dd class="font-medium text-zinc-900 dark:text-white">{{ __('Mon–Sat, 8am – 7pm EAT') }}</dd>
                        </div>
                    </dl>
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-6 dark:border-zinc-800 dark:bg-zinc-900/40">
                    <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('Already a customer?') }}</h2>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                        {{ __('Sign in and raise it from your dashboard — we will see which ISP you are without asking.') }}
                    </p>
                    <a href="{{ route('login') }}" wire:navigate class="mt-4 inline-block text-sm font-medium text-zinc-900 underline underline-offset-4 dark:text-white">
                        {{ __('Log in') }}
                    </a>
                </div>
            </aside>
        </div>
    </section>
</div>
