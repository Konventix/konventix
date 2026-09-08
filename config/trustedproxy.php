<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Konventix runs behind a reverse proxy that terminates TLS; the proxy
    | itself is out of scope for this application. Without the addresses below,
    | every request arrives looking like plain HTTP from the proxy's own IP,
    | and `X-Forwarded-Host` — the host the visitor actually typed — is
    | discarded. That last one is not cosmetic here: brands are resolved from
    | the request host (ADR-0001), so discarding it resolves the wrong brand or
    | none at all.
    |
    | Read from config rather than passed to `trustProxies(at: ...)` in
    | bootstrap/app.php on purpose. `config:cache` skips loading .env entirely,
    | so an `env()` call outside a config file returns null in production —
    | the operator's setting would be silently ignored and the default used.
    | Inside this file it is read at cache time and baked in.
    |
    | The default covers the RFC 1918 ranges a container network or a
    | co-located proxy lives on, plus loopback. It deliberately does NOT
    | default to `*`: trusting any client's `X-Forwarded-Host` lets a caller
    | that can reach the app directly choose which brand it is talking to and
    | which host signed URLs are generated against. Set `TRUSTED_PROXIES=*`
    | only where the application is genuinely unreachable except through the
    | proxy.
    |
    | On Kubernetes this default is a development default and nothing more
    | (#258). A cluster's pod CIDR falls inside 10.0.0.0/8, so leaving it in
    | place trusts every pod in the cluster, not only the ingress controller —
    | any neighbour can then forge `X-Forwarded-Host` and choose the brand, and
    | `X-Forwarded-Proto` and choose whether the request counts as secure. Such
    | a deployment sets `TRUSTED_PROXIES` to the ingress controller's own
    | address range; the chart passes it through as `app.trustedProxies`.
    |
    | What no longer hangs on this: the session cookie's `Secure` flag. It used
    | to be derived from `isSecure()`, and therefore from a header an untrusted
    | neighbour could write — `config/session.php` now decides it from the
    | environment instead.
    |
    */

    'proxies' => env('TRUSTED_PROXIES', '127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16'),

];
