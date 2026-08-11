{{-- Shown by the MikroTik immediately after a successful login, before it sends the customer
     on to wherever they were originally heading. Without this file the router serves an error
     at the exact moment the customer has just paid. --}}
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Connected</title>
<meta http-equiv="refresh" content="2; url=$(link-redirect)">
<style>
body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#fafafa;color:#3f3f46;
display:flex;align-items:center;justify-content:center;height:100vh;margin:0;text-align:center}
h1{color:#16a34a;font-size:20px;margin:0 0 8px}
p{margin:0;font-size:14px;color:#71717a}
</style>
</head>
<body>
<div>
<h1>You're online</h1>
<p>Enjoy your session.</p>
</div>
</body>
</html>
