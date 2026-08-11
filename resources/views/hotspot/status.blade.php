{{-- What is left of what they bought.

     The one page a customer comes looking for on purpose, so it answers the question they
     came with — how long have I got — before anything else. Every value is a RouterOS
     placeholder filled in by the device itself. --}}
<x-hotspot.shell
    :title="__('Your session')"
    :brand="$brand"
    :colour="$colour"
    :on-colour="$onColour"
>
    <h1>{{ __('You are online') }}</h1>
    <p>{{ __('Signed in as') }}</p>
    <p class="code">$(username)</p>

    <div class="rows">
        <div class="row">
            <span>{{ __('Time left') }}</span>
            <span>$(session-time-left)</span>
        </div>
        <div class="row">
            <span>{{ __('Used so far') }}</span>
            <span>$(uptime)</span>
        </div>
        <div class="row">
            <span>{{ __('Downloaded') }}</span>
            <span>$(bytes-out-nice)</span>
        </div>
        <div class="row">
            <span>{{ __('Uploaded') }}</span>
            <span>$(bytes-in-nice)</span>
        </div>
    </div>

    <a class="btn" href="{{ $portalUrl }}?mac=$(mac)&amp;ip=$(ip)&amp;link-login-only=$(link-login-only)">
        {{ __('Buy more time') }}
    </a>

    <p class="quiet"><a href="$(link-logout)">{{ __('Sign out') }}</a></p>
</x-hotspot.shell>
