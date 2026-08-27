# Platform — Specification

> Stable document. Describes what this module *is*. Task tracking lives in
> `TODO.md`.

## Purpose

Platform is the module every other module is wired into. It owns the operator
and its brands, resolves the brand from the request host, and holds everything
that is per-brand rather than per-event: design tokens, module activation,
navigation, UI slots, and the static pages a brand publishes on its own domain.
It absorbs LanCore's Theme and EventContext domains together with the central
wiring that used to live in a 408-line `AppServiceProvider` — but it holds that
wiring as *registries other modules contribute to*, never as knowledge of what
those modules contain (ADR-0003, lessons-from-lancore #3).

## Owns

- **Operator and Brand.** Operator data lives in database rows, not config, so a
  future multi-operator mode needs no schema archaeology (ADR-0001). A brand has
  one or more domains, a theme, an activation set, and its own static content.
- **Brand resolution.** The middleware that maps request host to brand, the
  container binding that makes the resolved brand available, brand-scoped
  sessions, and the serialization of brand context into queued jobs and its
  restoration in workers.
- **Brand scoping mechanics.** The `BelongsToBrand` trait — global scope plus
  automatic `brand_id` assignment on create — and the named
  `withoutBrandScope()` escape hatch behind an operator-level capability
  (ADR-0010). Other modules use the trait; they never reimplement it.
- **Theming.** Per-brand semantic design token sets, their contrast validation,
  and server-side injection into the Blade root template (ADR-0008). Platform
  owns the token vocabulary; components consume it and never reach past it to a
  palette value.
- **Plugin registry.** Module discovery, the base `ModuleServiceProvider` every
  module extends, and the `ensure-module` route middleware.
- **Pennant gating.** Feature definitions registered by each module at boot,
  per-brand activation state as data, and the four enforcement points: routes,
  navigation, UI slots, listeners (ADR-0003).
- **The waiting-room gate contract and its route alias** (ADR-0047 point
  5). `WaitingRoom\WaitingRoomGate` (`guard(Request): ?Response`) and the
  `ensure-waiting-room` middleware alias, registered beside
  `ensure-module`/`ensure-feature`. Platform binds nothing — Commerce's
  provider binds the one real implementation — so the middleware resolves
  the gate from the container lazily and degrades to a harmless
  pass-through when nothing is bound, a state a module applying the alias
  (Registration's public form, alongside Commerce's own `/shop`/`/cart`)
  must never be broken by. `Features\BrandFeature`'s
  `settingsComponent`/`settingsProps` is the related extension point a
  feature with its own settings uses to add a card to the brand edit
  page — a registered Vue component name plus a closure over the
  route-bound `Brand`, resolved only while the feature is active;
  deliberately not a `SlotRegistry` slot, since that registry's feature
  gate reads the request's *ambient* resolved brand, which the admin host
  never has (ADR-0029).
- **Navigation registry.** Modules contribute entries; Platform filters them by
  active feature and by permission at render time, so no component holds a list
  of modules (lessons-from-lancore #10).
- **Personal operator-console navigation state.** Per-user pinned admin
  favorites (`admin_favorites`) and the order the user dragged them into
  (ADR-0042). Registry order is Platform's; *this* order is the user's, and it
  is server-persisted so it follows them across browsers. Transient view state
  — which nav groups are expanded — stays client-side and is not data.
- **UI slot registry.** Named slots modules render into, gated per brand.
- **Block-based CMS for brand static pages.** Page and block models, publication
  state, and the block-type registry that modules register their own block types
  into. This covers a brand's own pages — landing page, imprint, info pages —
  not editorial content.
- **Per-brand role administration.** Roles and their permission grants are data
  scoped by `brand_id` as the spatie team key, plus the `permissions:sync`
  command that projects every module's capability enum into permission rows
  (ADR-0013). Platform owns the mechanism, never another module's enum. Each
  module declares its own starting roles through the `defaultRoles()`
  provider hook (ADR-0045) — the same declare-only pattern as
  `registerBlockTypes()`/`registerSlots()`/`registerCookies()` — and Platform
  materializes them per brand.
- **Cookie registry.** Every cookie the platform sets, declared by the
  module that sets it through the `registerCookies()` provider hook, so the
  cookie-settings dialog renders from that declaration and an undeclared
  cookie is a gap the dialog itself makes visible.

## Does not own

| Nearby concept | Owned by |
|---|---|
| Events, event lifecycle, venues | Event |
| Users, authentication, sessions, 2FA, social login | Identity — Platform only supplies the brand context a session is scoped to |
| Capability catalogues (`src/Enums/Permission.php`) | each module, for what it owns (ADR-0013) |
| Editorial content: news, announcements, comments, publishing workflow | Content — Platform's CMS covers brand static pages only |
| Policy documents, consent records, audit trail, retention rules | Compliance — Platform renders the privacy page, the policy version behind it is Compliance's |
| Mail templates, delivery, notification inbox | Messaging |
| Per-brand invoice number ranges, PSP account connections | Commerce (ADR-0006, ADR-0007) |
| Registration types, quotas, access rights | Registration and Admission |
| Seats, rooms, booths and their bookings | Resources |

The distinction that keeps drifting: *brand-scoped* is not the same as *owned by
Platform*. Nearly everything in the system is brand-scoped. Platform owns only
what describes the brand itself.

## Public contract (events)

Events are the only way other modules may react to this one (ADR-0004).
Payloads carry ULIDs and scalars — never Eloquent models.

No code exists yet. Every row below is a proposal and becomes binding when the
event class lands.

| Event | Emitted when | Payload | Status |
|---|---|---|---|
| `BrandCreated` | A brand is created under an operator | `brand_id`, `operator_id`, `slug`, `name` | proposed |
| `BrandRenamed` | A brand's public name or slug changes | `brand_id`, `slug`, `name` | proposed |
| `BrandDomainAdded` | A domain is attached to a brand | `brand_id`, `domain`, `is_canonical` | proposed |
| `BrandDomainRemoved` | A domain is detached from a brand | `brand_id`, `domain` | proposed |
| `BrandThemeUpdated` | A token set is saved and passes contrast validation | `brand_id`, `token_set_version` | proposed |
| `BrandArchived` | A brand is taken out of service | `brand_id`, `archived_at` | proposed |
| `ModuleActivated` | A module's feature is switched on for a brand | `brand_id`, `module_name` | proposed |
| `ModuleDeactivated` | A module's feature is switched off for a brand | `brand_id`, `module_name` | proposed |
| `StaticPagePublished` | A block-based brand page goes live | `brand_id`, `page_id`, `slug`, `published_at` | proposed |
| `StaticPageUnpublished` | A brand page is withdrawn | `brand_id`, `page_id`, `slug` | proposed |
| `RoleGrantsChanged` | A brand role's permission grants are edited | `brand_id`, `role_id`, `role_name` | proposed |

Activation materializes the module's default roles directly (ADR-0045); the
events stay proposed until a consumer outside Platform needs them.

`BrandResolved` is deliberately absent. Resolution happens on every request;
publishing it would invite queued listeners on the hot path. Modules read the
resolved brand from the container instead.

## Consumes

Events from other modules that this module listens to, and the read models it
maintains from them.

| Event | Owning module | Why |
|---|---|---|
| — | — | none by design |

Platform sits at the bottom of the dependency graph. Modules contribute to its
registries at boot; they do not push state into it at runtime. A listener here
on another module's event is an inverted dependency and should be treated as a
design error rather than implemented.

## Capabilities

Declared in `src/Enums/Permission.php`. Roles that grant them are per-brand
data, not code (ADR-0013).

Proposed catalogue: `ViewBrands`, `ManageBrands`, `ManageBrandDomains`,
`ManageBrandTheme`, `ManageModuleActivation`, `ViewStaticPages`,
`ManageStaticPages`, `ManageRoles`, `ViewOperator`,
`ManageOperator`, and the operator-level `UseCrossBrandScope` that unlocks
`withoutBrandScope()` (ADR-0010).

## Activation

Kernel module. Always active for every brand and not gateable: its provider sets
`$gateable = false` and its routes carry no `ensure-module` middleware. Platform
is the module that implements switching modules off, so it cannot be switched
off itself. Capabilities within it are permission-gated, which is a different
mechanism from activation.

## Open questions

- ~~Assigning a role to a user touches both a brand role (Platform) and a user
  (Identity). Which module owns that screen and that action, given neither may
  import the other's model?~~ **Decision log Round 6 (D-BATCH):** Platform
  owns the role-assignment screen; users are referenced by ULID, never an
  Identity model import.
- ~~ADR-0003 allows per-brand *and* per-event feature scoping through Pennant.
  Which Pennant scope object is canonical, and does Platform or Event own the
  mapping from a request to that scope?~~ **Decision log Round 6 (D-BATCH):**
  per-event Pennant scoping is deferred — brand remains the only scope object
  at release. Mirrored in Event's SPEC.
- ~~What renders on an existing static page when the module that registered
  one of its block types is deactivated for that brand — a placeholder,
  silent omission, or a publish-time rule that prevents the situation
  arising?~~ **Decision log Round 7 (D-BATCH-7):** silently omitted at
  render, matching the renderer's existing unknown/inactive-type behavior;
  the page editor shows a warning badge.
- ~~A brand with several domains needs a canonical one for URL generation,
  mail links, and redirects. Is canonicality a property of the brand or of
  the environment?~~ **Decision log Round 7 (D-BATCH-7):** canonicality is a
  property of the brand — the `is_primary` domain row already in the schema.
- ~~Do brands inherit token defaults from the operator, or is every brand's
  token set standalone?~~ **Decision log Round 7 (D-BATCH-7):** standalone
  per brand; no operator-level token inheritance.
