# Pointing a MikroTik HotSpot at the portal

What has to be done on the router itself before customers can buy access. The platform provisions
users onto an existing HotSpot; it does not create the HotSpot, the pool or the DHCP server.

Replace `PORTAL_HOST` with the address the platform is served on, and `PORTAL_KEY` with the
router's key (shown on its detail page).

## 1. Let unpaid customers reach the portal

A customer who has not logged in can reach nothing until the walled garden allows it. Without
this they see the login page and nothing else.

```
/ip hotspot walled-garden
add dst-host=PORTAL_HOST comment="ISP billing portal"
add dst-host=*.safaricom.co.ke comment="M-PESA"
```

If the platform is served over HTTPS on a name the router cannot resolve, add the IP form too:

```
/ip hotspot walled-garden ip
add dst-address=PORTAL_IP action=accept comment="ISP billing portal"
```

## 2. Send the login page to the portal

MikroTik serves `login.html` from its own files. Replacing it with a redirect hands the customer
to the portal, carrying the details the portal needs to log them back in.

Upload a `login.html` containing:

```html
<html>
  <head>
    <meta http-equiv="refresh" content="0; url=https://PORTAL_HOST/p/PORTAL_KEY?mac=$(mac)&ip=$(ip)&link-login-only=$(link-login-only)&link-orig=$(link-orig)">
  </head>
  <body>Redirecting…</body>
</html>
```

`$(...)` are MikroTik's own variables, substituted by the router when it serves the page.
`link-login-only` is the URL the portal posts credentials back to — without it the portal can
show a code but cannot connect the customer automatically.

## 3. Check the login methods

The portal posts a plain username and password, so the server profile must allow `http-pap`:

```
/ip hotspot profile print
```

`login-by` should include `http-pap`. On the test device `billing-hotspot-profile` already has
`http-chap,http-pap`.

## 4. M-PESA sandbox

Safaricom's sandbox short code (`174379`), passkey and test subscriber (`254708374149`) are
published and identical for everyone — they are already in `config/mpesa.php`. Only the consumer
key and secret are yours, from an app created at <https://developer.safaricom.co.ke>.

Put them in `.env`:

```
MPESA_CONSUMER_KEY=your-key
MPESA_CONSUMER_SECRET=your-secret
```

Callbacks come from Safaricom's servers, so the app has to be reachable from the internet. The
callback URL is built from `APP_URL`, so the tunnel address has to be set there **before**
configuring:

```bash
ngrok http 8000
```

Then set `APP_URL=https://your-tunnel.ngrok-free.app` and run:

```bash
php artisan mpesa:configure
```

It prints the callback URL, which goes into the Daraja app's STK push callback field. It warns
if the URL still points at localhost.

Fire a real prompt at the sandbox test number:

```bash
php artisan mpesa:test
```

Then follow what happened to it:

```bash
php artisan mpesa:status 1 --ask
```

`--ask` queries Safaricom directly and settles the payment from the answer, which is how to
recover when a callback was missed because the tunnel was down.

Sandbox notes worth knowing:

- Safaricom rejects `http://` and localhost callback URLs. The tunnel must be https.
- The sandbox only prompts `254708374149`. Any other number is refused.
- Sandbox access tokens are cached for 50 minutes; changing credentials mid-session may need
  `php artisan cache:clear`.

## PPPoE

Not usable on the test router yet — `/interface/pppoe-server/server` is empty. PPP secrets are
written correctly but nothing consumes them until a server exists:

```
/ip pool add name=pppoe-pool ranges=10.30.30.10-10.30.30.254
/ppp profile set [find name="PLAN_CODE"] local-address=10.30.30.1 remote-address=pppoe-pool
/interface pppoe-server server add service-name=isp interface=INTERFACE disabled=no default-profile=default
```

Pick `INTERFACE` deliberately: binding a PPPoE server to the bridge already carrying the HotSpot
will not do what you want.
