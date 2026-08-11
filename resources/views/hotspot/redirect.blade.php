{{-- The generic hop the router uses to move a client on to another address.

     Nothing here is worth reading, so it says as little as possible and gets out of the way.
     A device without this file serves a blank page at that moment instead. --}}
<x-hotspot.shell
    :title="__('One moment')"
    :brand="$brand"
    :colour="$colour"
    :on-colour="$onColour"
    refresh="0; url=$(link-redirect)"
>
    <div class="spin"></div>
    <p>{{ __('Taking you there…') }}</p>

    <x-slot:script>
        <script>location.replace("$(link-redirect)");</script>
    </x-slot:script>
</x-hotspot.shell>
