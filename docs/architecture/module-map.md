# Module Map

Mapping from the predecessor's 29 domains to Konventix's 19 modules. The renames are
not cosmetic — they force generic data models instead of relabeled LAN concepts.

## Kernel (always active)

| Konventix | Absorbs from LanCore | Owns |
|---|---|---|
| **Platform** | Theme, EventContext, central wiring | Brand model & resolution, theming/design tokens, plugin registry, Pennant gating, navigation registry, UI slot registry, block-based CMS for brand static pages (modules register their own block types), `Platform\Legal\PublicLegalTexts` — the contract for linking to a brand's published legal text without reading a policy; Platform declares it and binds a null default, Compliance binds the resolver (ADR-0004, #184), document watermarking (`Documents\DocumentWatermark`, ADR-0061) and the RFC 5545 calendar encoder (`Calendar\CalendarFile`, ADR-0061) — both take scalars and return bytes, callable by any module the way they all call `MediaLibrary` |
| **Identity** | Auth, Profile, Steam auth | User (slim!), social login as `SocialProvider` drivers (Steam is one of many), 2FA, sessions |
| **Event** | Event, Venue | Event lifecycle, venue as sub-concept |
| **Compliance** | Policy, DataLifecycle | Consent/policies, GDPR export & deletion orchestration (delegating to per-module `Gdpr/` exporters), audit, `CheckoutLegalTexts::SLUGS` — the checkout's `terms`/`withdrawal` roles (#200 AK 4) |
| **Messaging** | Notification, EmailLog | Channel abstraction (mail, push, in-app), delivery log as infrastructure; delivery log and template overrides have screens (`/admin/messages`) |
| **Support** | LanHelp (the retired satellite) | SupportRequest with its typed context snapshot, the thread (internal notes and external replies), triage state, assignment, known-issue notices, reporter access ("My support requests" and 30-day signed guest links). Kernel-style and **not** Pennant-gated (ADR-0063, amending ADR-0022): the launcher is on every brand host *and* on the operator console, which resolves no brand. LanHelp's knowledge base becomes a Content type instead (#48) |

## Core (every brand)

| Konventix | Absorbs | Owns |
|---|---|---|
| **Registration** | Ticketing (sales side) | RegistrationType with explicit axes, Registration (1) → Admissions/Bookings (N), Quota as standalone entity |
| **Admission** | Ticketing (entrance side) | Per-person access rights, badge, check-in, ticket tokens, entrance API (offline-capable client), the ticket page and the buyer's ticket manager (holders, claim invites); keeps a read model of each ticket's seat off Resources' booking events, while Resources keeps one of admissions off Admission's — two projections, never a shared table (#292) |
| **Commerce** | Shop, Cashier, PayPal | Cart, own Order/Invoice entities as source of truth, settlement adapters (Stripe, Mollie, bank transfer), vouchers, per-brand invoice number ranges, `Settlement\PayLink`/`Settlement\PaymentWindow` — where and how long an unpaid order stays payable (#5, #131) |
| **Content** | News, Announcement, Publishing | One publishing model, multiple content types, comments |

## Modules (Pennant-gated per brand)

| Konventix | Absorbs | Notes |
|---|---|---|
| **Staffing** | OrgaTeam | Differentiator: teams, shifts, shift roles, assignments, staffer read-model |
| **Resources** | Seating | Seat becomes one resource type with a **time dimension**; rooms, devices, booth space are future types. Since ADR-0060 the hall plan, the seat picker and seat claims are surfaces of this module: a place is a unit-mode resource, a category is a resource type, and the plan document is a typed JSON column projected into `resources` |
| **Competition** | Competition, CompetitionSchedule | Match results state stays here |
| **GameOps** | Orchestration, Games | The explicitly LAN-specific plugin; proves the plugin architecture. See ADR-0006 companion notes in `docs/decisions/` |
| **Agenda** | Program | |
| **Community** | Chat, Presence, Achievements | Cross-event participant community |
| **Sponsoring** | Sponsoring | Thin in LanCore; port late |
| **Newsletter** | Newsletter | Listmonk client behind a contract |
| **Extensibility** | Integration, Webhook, Api | Out-of-process tier: OAuth apps, webhooks. **Post-release** — do not publish external contracts before internal ones are stable |

## Release scope

Must ship (a brand's own events run end-to-end on it):
Platform, Identity, Event, Registration, Admission, Commerce, Compliance,
Messaging — plus Resources, Competition, GameOps, Content, Staffing
(our own events need them; Staffing is the first thing a pretix user sees
here and cannot get there).

Support ships with the release too, and is always on: the report channel
is the operator's early-warning system at go-live (#88, ADR-0063), not a
feature a brand opts into.

Deliberately after release: Community, Agenda, Sponsoring, Newsletter,
Extensibility.

## The satellites are gone

There is one application. LanBrackets is absorbed into Competition (ADR-0019),
LanShout into Community (ADR-0021), and LanHelp is retired in favour of the
Support module (ADR-0022). No future feature gets its own deployment.

The single exception is ADR-0002's: a genuinely offline-capable entrance client,
because a door that stops when the uplink drops ruins an event. That is a runtime
separation for an offline requirement, not a second application — and note the
predecessor's client was never actually offline (it always called the
authoritative endpoint), so this is new work rather than a port.
