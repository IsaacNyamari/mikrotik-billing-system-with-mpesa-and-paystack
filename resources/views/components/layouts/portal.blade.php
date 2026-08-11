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
                --accent: {{ $portal->accent() }};
                --on-accent: {{ $portal->onAccent() }};
                --accent-channels: {{ $portal->accentChannels() }};
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

            /* Banner ----------------------------------------------------------------- */
            /* The strip a customer reads first, confirming they are on the network whose name
               they just tapped. */
            .banner {
                display: flex; align-items: center; gap: .625rem;
                padding: .875rem 1rem; border-radius: .75rem;
                background: linear-gradient(100deg, var(--accent), var(--brand));
                color: var(--on-accent);
                font-weight: 650; font-size: .9375rem;
            }
            .banner__icon { flex: none; width: 1.5rem; height: 1.5rem; }

            /* Packages --------------------------------------------------------------- */
            /* Tiles two to a row on a phone, three where there is room. The ISP can force a
               single column instead. */
            .deals { display: grid; grid-template-columns: repeat(auto-fill, minmax(9.5rem, 1fr)); gap: .75rem; }
            .deals--list { grid-template-columns: 1fr; }

            .deal {
                display: flex; flex-direction: column; align-items: center; text-align: center;
                padding: 1.375rem .75rem .75rem; border-radius: .875rem; position: relative;
                background: #fff; border: 1px solid #e4e4e7;
                box-shadow: 0 1px 2px rgb(0 0 0 / .04);
            }
            .deal--tinted {
                background: linear-gradient(180deg, rgb(var(--brand-channels) / .16), rgb(var(--brand-channels) / .04));
                border-color: rgb(var(--brand-channels) / .28);
            }
            /* The duration, straddling the top edge like a label on a packet. */
            .deal__badge {
                position: absolute; top: -.625rem; left: 50%; transform: translateX(-50%);
                padding: .1875rem .625rem; border-radius: 999px; white-space: nowrap;
                background: var(--accent); color: var(--on-accent);
                font-size: .625rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase;
            }
            .deal__price { margin: .25rem 0 0; font-size: 1.625rem; font-weight: 800; line-height: 1.1; font-variant-numeric: tabular-nums; }
            .deal__price small { font-size: .75rem; font-weight: 600; opacity: .7; margin-right: .1875rem; }
            .deal__meta { margin: .25rem 0 0; font-size: .75rem; color: #71717a; }
            .deal__cta {
                margin-top: .875rem; width: 100%; padding: .5rem .5rem;
                font-family: inherit; font-size: .75rem; font-weight: 700; line-height: 1.3;
                border-radius: 999px; cursor: pointer;
                background: #fff; color: var(--brand); border: 1px solid rgb(var(--brand-channels) / .35);
                transition: background .15s, color .15s, transform .1s;
            }
            .deal--plain .deal__cta { background: var(--brand); color: var(--on-brand); border-color: transparent; }
            .deal__cta:hover { background: var(--brand); color: var(--on-brand); border-color: transparent; }
            .deal__cta:active { transform: scale(.98); }
            .deal__cta:disabled { opacity: .55; cursor: default; }

            /* In a single column there is room to put the money beside the description
               instead of under it. */
            .deals--list .deal { flex-direction: row; align-items: center; text-align: left; gap: 1rem; padding: 1rem; }
            .deals--list .deal__badge { position: static; transform: none; align-self: flex-start; }
            .deals--list .deal__body { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; align-items: flex-start; gap: .125rem; }
            .deals--list .deal__price { margin: 0; font-size: 1.375rem; }
            .deals--list .deal__cta { margin: 0; width: auto; flex: none; padding: .5rem 1rem; }

            /* The chosen package, restated above the phone field so nobody pays for the
               wrong thing. */
            .chosen { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }
            .chosen__name { font-weight: 650; }
            .chosen__meta { font-size: .8125rem; color: #71717a; }
            .chosen__price { font-size: 1.375rem; font-weight: 800; white-space: nowrap; }
            .link {
                background: none; border: 0; padding: 0; font: inherit; font-size: .8125rem;
                color: var(--brand); cursor: pointer; text-decoration: underline;
            }

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
                .card, .deal, .note--plain { background: #18181b; border-color: #3f3f46; }
                .deal--tinted {
                    background: linear-gradient(180deg, rgb(var(--brand-channels) / .22), rgb(var(--brand-channels) / .06));
                    border-color: rgb(var(--brand-channels) / .35);
                }
                .deal__cta { background: #27272a; color: #fafafa; border-color: #52525b; }
                .deal--plain .deal__cta { background: var(--brand); color: var(--on-brand); }
                .deal__cta:hover { background: var(--brand); color: var(--on-brand); }
                .portal__sub, .deal__meta, .chosen__meta { color: #a1a1aa; }
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
