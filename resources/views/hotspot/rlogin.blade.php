{{-- The router's own redirect step, used when it sends a client through the login flow
     without showing them anything.

     It exists so the device is not serving a blank page mid-hop. It carries the same details
     to the portal as login.html, because a customer who lands here has arrived by a different
     road to the same place. --}}
<x-hotspot.shell
    :title="__('Connecting…')"
    :brand="$brand"
    :colour="$colour"
    :on-colour="$onColour"
    refresh="0; url=$(link-login-only)?dst=$(link-orig-esc)"
>
    <div class="spin"></div>
    <p>{{ __('One moment.') }}</p>

    <x-slot:script>
        <script>location.replace("$(link-login-only)?dst=$(link-orig-esc)");</script>
    </x-slot:script>
</x-hotspot.shell>
