<x-layouts::auth :title="__('Register')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Start your ISP')"
            :description="__('Your account opens straight away. Pay the one-off setup fee when you are ready to go live.')"
        />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-6">
            @csrf
            <!-- Name -->
            <flux:input
                name="name"
                :label="__('Your name')"
                :value="old('name')"
                type="text"
                required
                autofocus
                autocomplete="name"
                :placeholder="__('Full name')"
            />

            <!-- Business name. Optional on purpose: somebody signing up from a phone should
                 not be stopped by a decision they have not made yet. -->
            <flux:input
                name="business_name"
                :label="__('Business name')"
                :value="old('business_name')"
                type="text"
                autocomplete="organization"
                :placeholder="__('Kawangware Wifi')"
                :description="__('Optional. This is what your customers see on your wifi login page — you can change it later.')"
            />

            <flux:input
                name="phone"
                :label="__('Phone')"
                :value="old('phone')"
                type="tel"
                autocomplete="tel"
                placeholder="07XX XXX XXX"
                :description="__('Optional. How we reach you about your account.')"
            />

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autocomplete="email"
                placeholder="email@example.com"
            />

            <!-- Password -->
            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <!-- Confirm Password -->
            <flux:input
                name="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Confirm password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="register-user-button">
                    {{ __('Create account') }}
                </flux:button>
            </div>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
            <span>{{ __('Already have an account?') }}</span>
            <flux:link :href="route('login')" wire:navigate>{{ __('Log in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
