{{-- A login the router refused: a code already spent, a mistyped one, or a package being used
     on more devices than it allows.

     The router's own wording is shown because it is the only thing that knows why. Sending
     them straight back to the packages is the useful move — most of these are somebody whose
     time has run out. --}}
<x-hotspot.shell
    :title="__('Could not sign in')"
    :brand="$brand"
    :colour="$colour"
    :on-colour="$onColour"
>
    <h1>{{ __('That did not work') }}</h1>
    <p>$(error)</p>

    <a class="btn" href="{{ $portalUrl }}?mac=$(mac)&amp;ip=$(ip)&amp;link-login-only=$(link-login-only)">
        {{ __('Buy a package') }}
    </a>

    <p class="quiet">{{ __('If you have just paid, wait a moment and try again — nothing is lost.') }}</p>
</x-hotspot.shell>
