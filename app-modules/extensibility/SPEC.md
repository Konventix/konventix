# Extensibility — Specification

> Stable document. Describes what this module *is*. Task tracking lives in
> `TODO.md`.

> **Post-release module, and the one with a hard precondition.** Extensibility
> is Phase 3 in `docs/ROADMAP.md`. Beyond the phase ordering, the module map is
> explicit: *do not publish external contracts before internal ones are stable*,
> and `docs/architecture/overview.md` lists "no public webhook/OAuth API until
> internal module contracts are stable" as a non-goal. The gating condition is
> the stability of the internal event contracts, not a date. Everything below is
> provisional.

## Purpose

Extensibility is the **out-of-process extension tier**. It is how anything that
does not run inside the monolith — a third-party integration, an operator's own
script, a proprietary plugin — talks to Konventix: OAuth applications and their
tokens, a versioned external HTTP API, and outbound webhooks. First-party
features are in-process modules communicating over internal Laravel events
(ADR-0002, ADR-0004); this module is the second tier, and only the second tier.
It owns transport and authorization. It owns no domain behaviour at all.

ADR-0011 makes this module load-bearing for the commercial model: proprietary
plugins must communicate with the AGPL core **only** over this out-of-process
tier, or across clearly separated module boundaries, for the licensing line to
stay defensible. That is a reason to get this tier right, not a reason to build
it early — a half-designed external surface that later has to change is worse
than none.

## Owns

- **External applications** — per-brand OAuth client registrations, their
  credentials, grants, redirect URIs, access and refresh tokens, and revocation.
- **Scope catalog and mapping** — the scopes an application may request and how
  each maps onto capabilities that other modules define.
- **Webhook subscriptions** — endpoint URL, selected event types, signing
  secret, activation state.
- **Delivery** — attempts, signing, retries and backoff, dead-lettering, and the
  delivery log.
- **The external API surface** — routing, authentication, rate limiting,
  versioning, and the serialization boundary of the public contract.
- **Translation** — turning internal events into outbound deliveries, and
  inbound authenticated requests into calls on the owning module's Actions.

## Does not own

This is the section that matters most for this module. Extensibility is a pipe.

- **Any domain state or domain decision.** It never decides whether a
  registration is confirmed, an order is paid, or an admission grants entry.
  Those belong to Registration, Commerce, and Admission respectively. An inbound
  API call authenticates here and is then executed by the owning module's own
  Action, with that module's rules and transactions.
- **The internal event contract** — each emitting module owns its own events
  (ADR-0004). Extensibility subscribes and projects outward. It never redefines
  a payload, never enriches one by reading another module's models, and never
  becomes the place where cross-module logic quietly accumulates.
- **Capability definitions** — every module's `src/Enums/Permission.php` is the
  authoritative catalog for what it owns (ADR-0013). OAuth scopes map onto those
  capabilities; Extensibility does not invent domain permissions of its own
  beyond administering apps and subscriptions.
- **Roles and grants** — per-brand data via spatie/laravel-permission
  (ADR-0013).
- **First-party frontend payloads** — Inertia plus laravel-data (ADR-0009).
  There is no REST API for our own frontend, and first-party pages must not be
  built on the external API: it is a separate contract with a separate
  versioning promise.
- **Human-facing notifications** — Messaging. A webhook is a machine delivery,
  not a notification channel.
- **Brand resolution, Pennant gating, the plugin registry** — Platform.
- **End-user authentication and sessions** — Identity. OAuth here authorizes
  *applications*; a person logging in is Identity's concern.
- **Anything that could be a first-party module.** If a feature can live
  in-process as a module, it is a module (ADR-0002). This tier is not a
  shortcut around module boundaries.

## Public contract (events)

Events are the only way other modules may react to this one (ADR-0004).
Payloads carry ULIDs and scalars — never Eloquent models.

| Event | Emitted when | Payload |
|---|---|---|
| None yet; see Open questions | | |

This module's outward contract is HTTP, not internal events. If it ever emits
anything, it will be operational (a delivery permanently failed, an app was
revoked) — never domain events, which belong to the modules that own the domain.

## Consumes

Events from other modules that this module listens to, and the read models it
maintains from them.

| Event | Owning module | Why |
|---|---|---|
| None yet; see Open questions | | |

In principle Extensibility subscribes to every module's public events — that is
what an outbound webhook tier is for. The concrete list cannot be fixed while
those contracts are still moving, which is exactly why this module is scheduled
after release. Any listener it does add must short-circuit when the feature is
off for the brand (ADR-0003).

## Capabilities

Declared in `src/Enums/Permission.php`. Roles that grant them are per-brand
data, not code (ADR-0013).

Not yet defined, and deliberately narrow when it is: administering OAuth
applications, administering webhook subscriptions, and reading the delivery log.
The permissions an application can act under are drawn from the owning modules'
catalogs through scope mapping, never redeclared here.

## Activation

Pennant feature: **`extensibility`**. Registration happens at boot regardless;
the feature gates routes, navigation, UI slots, and listeners (ADR-0003).

With the feature off, a brand has no external API, no OAuth application
registration, and no outbound webhooks. Nothing in the first-party product
degrades, because nothing in it depends on this module — which is why "off" is
the expected state for most brands, and the only state at release.

## Open questions

- API style, versioning promise, and deprecation policy. Publishing a contract
  is a commitment; this should become an ADR before a line of code.
- Scope granularity: how coarse or fine scopes are, and how they map onto
  module capability enums without duplicating them.
- Webhook delivery policy: signature scheme, secret rotation, retry and backoff
  schedule, dead-letter handling, replay of failed deliveries. Delivery likely
  needs its own queue name, given that deployment splits workers by queue.
- Rate limiting and quotas per application and per brand.
- How a proprietary plugin registers and is discovered, and precisely where the
  ADR-0011 licensing line falls for the in-process alternative ("clearly
  separated module boundaries").
- Whether the offline-capable entrance client (the ADR-0002 runtime-separation
  exception) uses this tier or a dedicated internal channel. It predates this
  module and probably should not wait for it.
- How LanBrackets' existing webhook integration (ADR-0012) relates to this tier —
  it is inbound from an external service today and may be a first customer of
  the same machinery, or may stay separate.
