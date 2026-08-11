@props([
    'title',
    'brand',
    'colour',
    'onColour',
    // A meta-refresh, for the pages whose whole job is to move the customer on.
    'refresh' => null,
])

{{-- The frame every HotSpot page shares.

     Styles are inline and nothing external is loaded, on purpose: a phone reading one of
     these is unauthenticated or has just been cut off, so a stylesheet on another host is one
     that will not arrive. The ISP's colour is baked in when the router fetches the page. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title }}</title>
@if ($refresh)
<meta http-equiv="refresh" content="{!! $refresh !!}">
@endif
<style>
:root{--b:{{ $colour }};--on-b:{{ $onColour }}}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.25rem;
font-family:system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",sans-serif;
background:#f4f4f5;color:#18181b;line-height:1.5}
.card{width:100%;max-width:22rem;background:#fff;border:1px solid #e4e4e7;border-radius:.9rem;
padding:1.75rem 1.25rem;text-align:center;box-shadow:0 1px 3px rgb(0 0 0/.06)}
.brand{font-size:.8125rem;font-weight:600;color:var(--b);letter-spacing:.02em;margin:0 0 1rem}
h1{margin:0 0 .5rem;font-size:1.25rem;font-weight:650}
p{margin:.25rem 0 0;font-size:.875rem;color:#52525b}
.btn{display:block;margin-top:1.25rem;padding:.75rem 1rem;border:0;border-radius:.5rem;width:100%;
background:var(--b);color:var(--on-b);font:inherit;font-weight:600;text-decoration:none;cursor:pointer}
.quiet{margin-top:.75rem;font-size:.8125rem;color:#71717a}
.quiet a{color:var(--b)}
.rows{margin:1.25rem 0 0;text-align:left;font-size:.875rem;border-top:1px solid #f4f4f5}
.row{display:flex;justify-content:space-between;gap:1rem;padding:.5rem 0;border-bottom:1px solid #f4f4f5}
.row span:last-child{font-weight:600;font-variant-numeric:tabular-nums}
.code{margin:.75rem 0 0;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:1.25rem;
font-weight:700;letter-spacing:.1em;word-break:break-all}
.spin{width:2rem;height:2rem;margin:0 auto 1rem;border:3px solid #e4e4e7;border-top-color:var(--b);
border-radius:50%;animation:s .8s linear infinite}
@keyframes s{to{transform:rotate(360deg)}}
@media(prefers-reduced-motion:reduce){.spin{animation:none}}
</style>
</head>
<body>
<div class="card">
<p class="brand">{{ $brand }}</p>
{{ $slot }}
</div>
@isset($script)
{!! $script !!}
@endisset
</body>
</html>
