{{--
    The way back in for somebody who paid and never got online.

    A form, not a link that opens one. Whoever needs this is standing there with no internet and
    a payment they have already made, and asking them to find and tap something before they can
    type is one obstacle too many at exactly the wrong moment. The field is simply there.

    Whatever went wrong — the router was down, the phone hopped to mobile data for the M-PESA
    prompt and never came back, the tab was closed, the package was bought at the counter — they
    are holding an SMS with a code in it, and that code is the whole of what is asked for.

    @param string $heading
--}}

<div class="card recover">
    <h2 class="recover__title">{{ $heading ?? __('Already paid?') }}</h2>
    <p class="recover__hint">
        {{ __('Enter the code from your M-PESA message and we will put you back online.') }}
    </p>

    <form wire:submit="recover" class="recover__form">
        <div class="field">
            <label class="field__label sr-only" for="portal-code">{{ __('M-PESA code') }}</label>

            <div class="recover__row">
                <input
                    id="portal-code"
                    wire:model="code"
                    type="text"
                    inputmode="latin"
                    autocapitalize="characters"
                    autocomplete="off"
                    autocorrect="off"
                    spellcheck="false"
                    maxlength="32"
                    placeholder="{{ __('e.g. QGR4TZ8LMN') }}"
                    @class(['field__input', 'field__input--code', 'field__input--bad' => $errors->has('code')])
                >

                <button type="submit" class="btn recover__go" wire:loading.attr="disabled" wire:target="recover">
                    <svg wire:loading wire:target="recover" class="spinner" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" opacity=".25"/>
                        <path fill="currentColor" opacity=".75" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                    </svg>
                    <span wire:loading.remove wire:target="recover">{{ __('Connect') }}</span>
                    <span wire:loading wire:target="recover">{{ __('Checking…') }}</span>
                </button>
            </div>

            @error('code')
                <p class="field__error">{{ $message }}</p>
            @enderror
        </div>
    </form>
</div>
