{{-- Served to the MikroTik, which stores it as hotspot/login.html and substitutes its own
     $(...) variables before sending it to a customer's phone.

     Everything a customer actually sees lives on the platform's portal; this page exists only
     to hand them over, carrying the details the portal needs to log them back in. --}}
<x-hotspot.shell
    :title="__('Connecting…')"
    :brand="$brand"
    :colour="$colour"
    :on-colour="$onColour"
    :refresh="'0; url='.$portalUrl.'?mac=$(mac)&amp;ip=$(ip)&amp;username=$(username)&amp;link-login-only=$(link-login-only)&amp;link-orig=$(link-orig)&amp;error=$(error)'"
>
    <div class="spin"></div>
    <h1>{{ __('Taking you to the packages') }}</h1>
    <p>{{ __('One moment.') }}</p>

    {{-- A phone with JavaScript and meta-refresh both blocked still gets a way through. --}}
    <p class="quiet">
        <a href="{{ $portalUrl }}?mac=$(mac)&amp;ip=$(ip)&amp;link-login-only=$(link-login-only)">
            {{ __('Tap here if nothing happens') }}
        </a>
    </p>

    <x-slot:script>
        <script>
        location.replace({!! json_encode($portalUrl) !!}
          + "?mac=$(mac)&ip=$(ip)&link-login-only=$(link-login-only)&link-orig=$(link-orig)");
        </script>
    </x-slot:script>
</x-hotspot.shell>
