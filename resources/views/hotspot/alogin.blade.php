{{-- Shown by the MikroTik immediately after a successful login, before it sends the customer
     on to wherever they were originally heading. Without this file the router serves an error
     at the exact moment the customer has just paid. --}}
<x-hotspot.shell
    :title="__('Connected')"
    :brand="$brand"
    :colour="$colour"
    :on-colour="$onColour"
    refresh="2; url=$(link-redirect)"
>
    <h1 style="color:#16a34a">{{ __('You are online') }}</h1>
    <p>{{ __('Enjoy your session.') }}</p>

    <p class="quiet">
        <a href="$(link-redirect)">{{ __('Continue') }}</a>
    </p>
</x-hotspot.shell>
