{{-- After signing out.

     Their code usually still has time on it, so this says so rather than treating a sign-out
     as the end of the transaction — a customer who thinks their money is gone is a customer
     at the counter. --}}
<x-hotspot.shell
    :title="__('Signed out')"
    :brand="$brand"
    :colour="$colour"
    :on-colour="$onColour"
>
    <h1>{{ __('Signed out') }}</h1>
    <p>{{ __('You used $(uptime) of your time.') }}</p>

    <div class="rows">
        <div class="row">
            <span>{{ __('Downloaded') }}</span>
            <span>$(bytes-out-nice)</span>
        </div>
        <div class="row">
            <span>{{ __('Uploaded') }}</span>
            <span>$(bytes-in-nice)</span>
        </div>
    </div>

    <a class="btn" href="$(link-login)">{{ __('Sign back in') }}</a>

    <p class="quiet">{{ __('If your package still has time left, your code will work again.') }}</p>
</x-hotspot.shell>
