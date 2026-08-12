# Konventix — System Overview

## What this is

Konventix is a self-hostable event management platform for the DACH market,
covering registration, admission, commerce, staffing, resources, content, and
competitions. It generalizes LanCore (a LAN-party tool) into an event-agnostic
platform. Differentiation vs. pretix/Eventbrite: volunteer/shift planning,
cross-event participant communities, resource management beyond seating,
tournament systems.

## Operator → Brand → Event model

- **Operator**: the legal entity running the instance (initially Sund-Xplosion
  e.V.). Exactly one per instance today, but operator data lives in database
  rows, not config, to keep multi-operator possible later (ADR-0001).
- **Brand**: a public identity with its own domain(s), theme, content, and
  module activation set (e.g. sxlan.de, xpertise.sxcs.de).
- **Event**: a concrete occurrence under a brand, with its own registration
  types, resources, schedule.

This is explicitly **not** classical multi-tenancy. There is no tenant
isolation layer; there is brand *scoping*.

### Request flow

1. Middleware resolves the brand from the request host (FrankenPHP/Caddy
   on-demand TLS serves arbitrary brand domains).
2. Brand context is bound into the container; sessions are brand-scoped.
3. The `BelongsToBrand` global scope constrains all brand-owned queries.
4. Brand context is serialized into queued jobs and restored in workers.
5. Pennant feature checks gate module functionality per brand at four
   enforcement points: **routes, navigation, UI slots, event listeners**.

## Modular monolith

One Laravel application, one deployment unit. Modules live in `app-modules/*`
(InterNACHI/modular-style), each self-registering via its own ServiceProvider.
Runtime separation is reserved for genuine offline requirements only
(e.g. venue entrance scanning must work without the main app).

Two-tier extensibility:
- **First-party modules**: in-process, share the database, communicate via
  internal Laravel events.
- **External apps**: out-of-process, via webhooks + OAuth (Extensibility
  module, post-release).

## Layering

| Layer | Modules | Notes |
|---|---|---|
| Kernel | Platform, Identity, Event, Compliance, Messaging | Always active, not gateable |
| Core | Registration, Admission, Commerce, Content | Every brand needs them |
| Modules | Staffing, Resources, Competition, GameOps, Agenda, Community, Sponsoring, Newsletter, Extensibility | Pennant-gated per brand |

## Module anatomy (canonical layout)

```
app-modules/<name>/
├── SPEC.md               # what the module does — stable
├── TODO.md               # tasks — living, updated in PRs
├── composer.json
├── config/
├── database/migrations/ + factories/
├── routes/web/<name>.php  # one level deeper than Laravel's default, see below
├── resources/js/pages/ + components/
├── src/
│   ├── Providers/       # <Name>ServiceProvider — registers everything itself
│   ├── Models/          # incl. read models of foreign data
│   ├── Data/            # laravel-data: validation + props + TS types
│   ├── Enums/           # incl. Permission.php (capability catalog)
│   ├── Actions/         # business logic, DB::transaction inside
│   ├── Events/          # the module's public contract
│   ├── Listeners/       # reactions to other modules' events
│   ├── Http/Controllers/ + Middleware/
│   ├── Policies/
│   ├── Navigation/      # registers nav entries with Platform
│   └── Gdpr/            # per-module export/anonymization
└── tests/
```

Every `<Name>ServiceProvider` extends `Konventix\Platform\Modules\ModuleServiceProvider`,
which supplies the registration hooks and applies route middleware. Modules are
never listed in `bootstrap/providers.php`; they self-register through their own
`composer.json` (`extra.laravel.providers`).

Route files sit in `routes/web/`, not `routes/`, because InterNACHI/modular
auto-`require`s every `routes/*.php` at depth 0 with no middleware at all — a
route there would escape both the `web` group and the activation gate. One level
deeper, the module's provider is the only thing that can register them, and it
always applies the gate. An architecture test fails the build on strays.

## Deployment

One Docker image, multiple roles: web (FrankenPHP/Octane), queue workers split
by queue name (long-running provisioning isolated with extended timeout),
scheduler, and one shared Reverb websocket server with one app entry per brand
(ADR-0025). PostgreSQL and Redis as separate services. Roles are separate
containers via command override — the application never spawns containers
itself.

## Non-goals (write it down so nobody builds it)

- No classical multi-tenancy / tenant self-signup at release.
- No support-only revenue model; commercial path is AGPL open core + hosted
  SaaS + proprietary plugins (ADR-0011).
- No public webhook/OAuth API until internal module contracts are stable.
