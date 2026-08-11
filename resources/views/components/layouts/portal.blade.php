<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        {{-- Mobile-first: almost everyone meets this page on a phone. --}}
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>{{ $title ?? __('Get online') }}</title>

        {{-- Deliberately no @vite and no Flux here.

             The captive portal is the one page that has to render for someone who has paid
             nothing and can reach almost nothing. Pulling a compiled stylesheet in would tie
             it to the asset build: a stale public/hot points every URL at a Vite dev server on
             localhost, which a phone can never reach, and the customer gets unstyled HTML at
             the exact moment they are deciding whether to trust this network with their money.

             Inlining costs a couple of kilobytes and removes the whole failure mode — there is
             no second request left to fail. It also keeps the portal's look independent of the
             admin UI, which is a different product for a different audience. --}}
        @php($portal = $portalSetting ?? App\Models\PortalSetting::current())

        <style>
            /* Everything the ISP controls arrives as a custom property, so the rest of the
               stylesheet is fixed and only these few values vary per tenant. The colours are
               validated by the model before they get here — they are written into a stylesheet
               and so can never be arbitrary text. */
            :root {
                --brand: {{ $portal->primary() }};
                --brand-dark: {{ $portal->primaryDark() }};
                --on-brand: {{ $portal->onPrimary() }};
                --brand-channels: {{ $portal->primaryChannels() }};
            }

            *, *::before, *::after { box-sizing: border-box; }
            html { -webkit-text-size-adjust: 100%; }
            body {
                margin: 0;
                font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", sans-serif;
                background: #f4f4f5;
                color: #18181b;
                line-height: 1.5;
                /* Room for phones with a home indicator or notch. */
                padding: env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left);
            }

            .portal { max-width: 32rem; margin: 0 auto; padding: 1rem; min-height: 100vh; display: flex; flex-direction: column; gap: 1.25rem; }
            .portal__head { text-align: center; padding-top: 1rem; }
            .portal__title { margin: 0; font-size: 1.5rem; font-weight: 650; letter-spacing: -0.01em; }
            .portal__sub { margin: .25rem 0 0; font-size: .875rem; color: #71717a; }
            .portal__foot { margin-top: auto; padding: 1rem 0; text-align: center; font-size: .75rem; color: #a1a1aa; }
            .portal__foot a { color: inherit; }
            /* Capped rather than sized, so any logo an ISP uploads sits sensibly without
               having to be a particular shape. */
            .portal__logo { max-height: 4rem; max-width: 100%; width: auto; margin: 0 auto .75rem; display: block; }

            .card { background: #fff; border: 1px solid #e4e4e7; border-radius: .75rem; padding: 1.25rem; box-shadow: 0 1px 2px rgb(0 0 0 / .04); }

            /* Plans ------------------------------------------------------------------ */
            .plans { display: flex; flex-direction: column; gap: .75rem; margin: 0; padding: 0; border: 0; }
            .plan {
                display: flex; align-items: center; gap: 1rem; cursor: pointer;
                background: #fff; border: 1px solid #e4e4e7; border-radius: .75rem; padding: 1rem;
                box-shadow: 0 1px 2px rgb(0 0 0 / .04);
                transition: border-color .15s, box-shadow .15s;
            }
            .plan:hover { border-color: #d4d4d8; }
            .plan--on { border-color: var(--brand); box-shadow: 0 0 0 3px rgb(var(--brand-channels) / .18); }
            .plan input[type="radio"] { flex: none; width: 1.25rem; height: 1.25rem; accent-color: var(--brand); margin: 0; }
            .plan__body { flex: 1 1 auto; min-width: 0; }
            .plan__name { font-weight: 600; }
            .plan__meta { font-size: .8125rem; color: #71717a; }
            .plan__price { flex: none; text-align: right; }
            .plan__amount { font-size: 1.25rem; font-weight: 700; font-variant-numeric: tabular-nums; }
            .plan__currency { display: block; font-size: .6875rem; color: #a1a1aa; }

            /* Form ------------------------------------------------------------------- */
            .field { display: block; }
            .field__label { display: block; margin-bottom: .375rem; font-size: .875rem; font-weight: 500; color: #3f3f46; }
            .field__input {
                display: block; width: 100%; padding: .7rem .75rem;
                /* 16px stops iOS zooming the page when the field is focused. */
                font-size: 1rem; font-family: inherit; color: #18181b;
                background: #fff; border: 1px solid #d4d4d8; border-radius: .5rem;
                outline: none; transition: border-color .15s, box-shadow .15s;
            }
            .field__input:focus { border-color: var(--brand); box-shadow: 0 0 0 3px rgb(var(--brand-channels) / .18); }
            .field__input--bad { border-color: #dc2626; }
            .field__input--bad:focus { border-color: #dc2626; box-shadow: 0 0 0 3px rgb(220 38 38 / .15); }
            .field__error { margin: .375rem 0 0; font-size: .8125rem; font-weight: 500; color: #dc2626; }

            .btn {
                display: flex; align-items: center; justify-content: center; gap: .5rem; width: 100%;
                padding: .8rem 1rem; font-size: 1rem; font-weight: 600; font-family: inherit;
                color: var(--on-brand); background: var(--brand); border: 0; border-radius: .5rem; cursor: pointer;
                transition: background .15s;
            }
            .btn:hover { background: var(--brand-dark); }
            .btn:disabled { opacity: .6; cursor: default; }
            .btn--quiet { background: transparent; color: #3f3f46; border: 1px solid #d4d4d8; }
            .btn--quiet:hover { background: #f4f4f5; }

            /* Notices ---------------------------------------------------------------- */
            .note { border-radius: .75rem; padding: 1.25rem; text-align: center; border: 1px solid; }
            .note__title { margin: .5rem 0 0; font-size: 1.0625rem; font-weight: 650; }
            .note__text { margin: .25rem 0 0; font-size: .875rem; }
            .note--good { background: #f0fdf4; border-color: #bbf7d0; color: #14532d; }
            .note--bad { background: #fef2f2; border-color: #fecaca; color: #7f1d1d; }
            .note--warn { background: #fffbeb; border-color: #fde68a; color: #78350f; }
            .note--plain { background: #fff; border-color: #e4e4e7; }

            .code {
                margin: 1rem 0 0; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                font-size: 1.75rem; font-weight: 700; letter-spacing: .15em; word-break: break-all;
            }

            .spinner { width: 1.25rem; height: 1.25rem; animation: spin .8s linear infinite; }
            .pulse { animation: pulse 1.8s ease-in-out infinite; }
            @keyframes spin { to { transform: rotate(360deg); } }
            @keyframes pulse { 50% { opacity: .45; } }
            @media (prefers-reduced-motion: reduce) {
                .spinner, .pulse { animation: none; }
            }

            .icon { width: 3rem; height: 3rem; }
            [hidden] { display: none !important; }

            /* Dark ------------------------------------------------------------------- */
            /* An ISP that has chosen a fixed scheme gets it unconditionally; on "auto" the
               same rules ride on the phone's own preference. */
@if ($portal->color_scheme !== 'light')
@if ($portal->color_scheme === 'auto')
            @media (prefers-color-scheme: dark) {
@endif
                body { background: #09090b; color: #fafafa; }
                .card, .plan, .note--plain { background: #18181b; border-color: #3f3f46; }
                .plan:hover { border-color: #52525b; }
                .plan--on { border-color: var(--brand); box-shadow: 0 0 0 3px rgb(var(--brand-channels) / .3); }
                .portal__sub, .plan__meta { color: #a1a1aa; }
                .field__label { color: #d4d4d8; }
                .field__input { background: #18181b; border-color: #52525b; color: #fafafa; }
                .field__input:focus { border-color: var(--brand); box-shadow: 0 0 0 3px rgb(var(--brand-channels) / .3); }
                .note--good { background: #052e16; border-color: #166534; color: #dcfce7; }
                .note--bad { background: #450a0a; border-color: #991b1b; color: #fee2e2; }
                .note--warn { background: #451a03; border-color: #92400e; color: #fef3c7; }
                .btn--quiet { color: #d4d4d8; border-color: #52525b; }
                .btn--quiet:hover { background: #27272a; }
@if ($portal->color_scheme === 'auto')
            }
@endif
@endif
@if ($portal->custom_css)

            /* Supplied by the ISP. Angle brackets are stripped by the model, so this cannot
               close the element early and start a script. */
            {!! $portal->safeCustomCss() !!}
@endif
        </style>
    </head>
    <body>
        {{ $slot }}
    </body>
</html>
