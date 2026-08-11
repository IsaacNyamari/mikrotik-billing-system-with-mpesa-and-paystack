{{-- Served to the MikroTik, which stores it as hotspot/login.html and substitutes its own
     $(...) variables before sending it to a customer's phone.

     Everything a customer actually sees lives on the platform's portal; this page exists only
     to hand them over, carrying the details the portal needs to log them back in. --}}
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Connecting…</title>
<meta http-equiv="refresh" content="0; url={{ $portalUrl }}?mac=$(mac)&amp;ip=$(ip)&amp;username=$(username)&amp;link-login-only=$(link-login-only)&amp;link-orig=$(link-orig)&amp;error=$(error)">
<style>
body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#fafafa;color:#3f3f46;
display:flex;align-items:center;justify-content:center;height:100vh;margin:0;text-align:center}
.b{border:3px solid #e4e4e7;border-top-color:#2563eb;border-radius:50%;width:32px;height:32px;
margin:0 auto 16px;animation:s .8s linear infinite}
@keyframes s{to{transform:rotate(360deg)}}
a{color:#2563eb}
</style>
</head>
<body>
<div>
<div class="b"></div>
<p>Taking you to the sign-in page…</p>
{{-- A phone with JavaScript and meta-refresh both blocked still gets a way through. --}}
<p><a href="{{ $portalUrl }}?mac=$(mac)&amp;ip=$(ip)&amp;link-login-only=$(link-login-only)">Tap here if nothing happens</a></p>
</div>
<script>
location.replace({!! json_encode($portalUrl) !!}
  + "?mac=$(mac)&ip=$(ip)&link-login-only=$(link-login-only)&link-orig=$(link-orig)");
</script>
</body>
</html>
