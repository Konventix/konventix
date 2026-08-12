# ADR-0025: One shared Reverb server, one app entry per brand

**Status:** accepted — supersedes the process topology of ADR-0021

## Context

ADR-0021 decided Reverb runs **per brand domain** — one websocket process per
brand — and rejected a shared websocket host on two grounds: cross-origin
websocket authentication from N brand domains, and brand isolation resting on a
channel-naming convention. Implementation research against the actual Reverb
feature set showed both premises are wrong:

- Reverb officially serves **multiple applications from one process** (the
  `apps` array in `config/reverb.php`). Each app entry has its own
  `app_id`/`key`/`secret`, its own `allowed_origins`, and its own
  `max_connections`. Subscriptions and auth signatures are bound to the app
  key/secret, so a client authenticated against brand A's app cannot subscribe
  to brand B's channels **regardless of channel names**. That is structural
  isolation at the credential level — the property ADR-0021 wanted from
  per-brand processes — without any channel-naming convention.
- The cross-origin problem dissolves with infrastructure Konventix already has:
  Caddy proxies `wss://<brand-domain>/ws` to the one Reverb backend. Every
  brand's websocket endpoint stays on that brand's own domain and certificate;
  the auth XHR to `/broadcasting/auth` stays same-origin. No shared `ws.` host
  ever appears.
- The ecosystem is unanimous: stancl/tenancy's broadcasting integration,
  spatie/laravel-multitenancy community patterns, and Pusher/Ably's entire
  product model are per-tenant *app credentials* on shared websocket
  infrastructure. One-process-per-tenant appears in no official docs, no
  tenancy package, and no platform product; Laravel Cloud runs shared managed
  Reverb clusters. Reverb's documented scaling axis is horizontal peers over
  Redis pub/sub — equivalent instances serving the same app set — not per-app
  sharding.

What genuinely survives of ADR-0021's reasoning is blast-radius isolation: a
shared process has one event loop, so a connection stampede against brand A
could in principle starve brand B. At Konventix scale (LAN-party events,
hundreds of concurrent connections against a ~1,024-fd default ceiling, more
with `ext-uv`) this is a small risk, and per-app `max_connections` caps it
per brand.

## Decision

**Reverb remains a deployment role of the one image (ADR-0002), but it is ONE
shared server, not a per-brand fleet. Each brand is an entry in Reverb's
`apps` provider.**

- One `reverb:start` process (horizontally scalable later via
  `REVERB_SCALING_ENABLED` + Redis) serves every brand.
- Per brand: own `app_id`/`key`/`secret`, `allowed_origins` pinned to that
  brand's domains, and a `max_connections` budget. Brand isolation is enforced
  by credentials, not by channel naming and not by process boundaries.
- Caddy proxies `wss://<brand-domain>/ws` to the Reverb backend, so the
  websocket connection still lives on the brand's own domain and certificate,
  and channel authorization still runs same-origin against the brand's own
  session. Everything ADR-0021 valued about "the brand's own domain" is kept.
- Reverb's default `config` apps provider is static; brands are database rows.
  When Phase 2 wires realtime, brand credentials move behind a database-backed
  `ApplicationProvider` (Reverb's extension point for exactly this), so
  creating a brand provisions its websocket app without a config deploy.
  Until then the single env-driven app entry serves local dev.
- Workers, scheduler and web are unchanged: one image, one container per role,
  command override — never dynamic container spawning from the application
  (a docker-socket mount is root-equivalent host access and stays banned).

## Consequences

- The per-brand cost curve ADR-0021 accepted ("a process, a supervision entry
  and a connection budget for every brand") disappears; adding a brand becomes
  a data operation, which also removes ADR-0021's own revisit-trigger (brands
  becoming cheap to create casually is now harmless).
- Blast-radius isolation weakens from process-level to credential-level.
  Mitigations: per-app `max_connections`, per-app rate limiting, and — if a
  brand ever outgrows the shared loop — Redis-scaled horizontal instances.
  The trigger for revisiting is sustained event-loop saturation attributable
  to one brand, evidenced by Pulse/monitoring, not by brand count.
- A misconfigured broadcast that signs brand A's events with brand B's
  credentials becomes *possible* (a per-brand process could not express this).
  The broadcaster resolution therefore belongs next to brand resolution in
  Platform, and an architecture test should pin "current brand ⇒ current
  brand's Reverb app".
- Brand resolution still gains a websocket-handshake code path (Caddy route +
  origin pinning), as ADR-0021 already required.
- ADR-0021's remaining content stands: broadcasting abstraction only,
  LanShout deleted, bell + board in Phase 2, chat + presence inheriting the
  transport in Phase 3.
