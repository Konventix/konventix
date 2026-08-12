# Roadmap

## Phase 0 — before the first commit (blockers)

Cleared.

- [x] IP position settled (ADR-0011). No agreement with the Verein was needed:
      the predecessor (Lan-Software / LanCore) already ran under Markus Kohn's
      own name, so sole copyright was never in question.
- [x] konventix.de, konventix.eu and the GitHub organisation registered and held
      by Markus Kohn. Trademark search (DPMAregister, TMview, EUIPO eSearch)
      still outstanding before public launch — note the phonetically similar
      "conventic GmbH".
- [x] LICENSE (AGPL-3.0-or-later) and this docs set committed.

## Phase 1 — PoC (riskiest unknowns only; no payment, no tournament logic)

Proven:
- [x] Multi-domain brand resolution: ResolveBrand fronts the `web` group
      (every request runs in a brand or 404s), Caddy on-demand TLS `ask`
      endpoint refuses unknown hosts, dev web role runs FrankenPHP via
      Octane. Outstanding: the production Caddyfile wiring `on_demand_tls`
      to `/caddy/ask` (deployment config, lands with the prod image).
- [x] Brand-scoped sessions (per-brand cookie names) + brand context
      serialized into queued jobs and restored in workers
- [x] Pennant module gating at all four points (routes, nav, slots,
      listeners — `ShortCircuitsInactiveModule`)
- [x] Block-based static brand pages: `BlockType` contract + registry via the
      `registerBlockTypes()` provider hook, render pipeline at `/pages/{slug}`
- [x] `BelongsToBrand` scope + strict models + laravel-data → TS pipeline
      (`composer types:generate` → `resources/js/types/generated.d.ts`)
- [x] One registration flow with multiple RegistrationTypes (axes model),
      free settlement only: submit → (approve) → confirm emits
      `RegistrationConfirmed`; Admission issues idempotently from the payload
      alone; Commerce stays dormant (ADR-0026)

## Phase 2 — release scope

**Round 6 gate review:** the spec-out session's decision set is now closed
(`docs/inventory/decision-log.md`, "Round 6 — the Phase 2 gate review").
Five user overrides added release scope beyond the recommended minimums: the
event-day concept, multi-venue events, web push, Messaging-owned digests, and
Registration-owned waitlists. See each affected module's `SPEC.md`/`TODO.md`
for what that adds to its definition of done.

Kernel: Platform, Identity, Event, Compliance, Messaging — **started**:
- Operator admin console (ADR-0029): own host resolved into OperatorContext,
  hardened brand scope, `permissions:sync` + Operator Admin role (ADR-0013
  live), module-contributed `routes/admin/` + admin navigation; Brands and
  Users pages shipped. Demo data via `konventix:seed` (Laravel Prompts).
- Identity: **auth lifecycle complete** — registration, email verification,
  2FA (confirmed), session list/revocation, account states, auth Data
  objects; `brand_memberships` (ADR-0016) recorded on login/registration
- LanCore UX adapted (same stack, so ports not rewrites): admin shell
  (module-grouped collapsible sidebar, search, server-persisted favorites),
  shared DataTable pattern, brand landing pages as block compositions
  (hero/cards from Platform, event-hero/countdown contributed by Event —
  the ADR-0003 extension proof), PublicTopbar, brand theme editor with
  BFSG contrast enforcement (ADR-0008 "refuses, not warns" — which caught
  and fixed non-compliant default status colors), per-event admin dashboard
  composed via UI slots (Registration/Admission contribute their own cards
  and endpoints). Deferred from LanCore: seat-map viewer (needs Resources),
  audit pages, dark-mode theme overrides.
- Event: Event + Venue models (wall-clock times, ADR-0027), lifecycle
  Actions + contract events, public landing route
- Compliance: policies/versions, consent records, `GdprHandler` contract +
  registry, DataSubjectRequest workflow with idempotent re-dispatch
- Messaging: `MessageChannel` contract + mail driver with per-brand
  transports (ADR-0028), DB-backed template registry with brand overrides,
  outbound queue pipeline
- Realtime transport: shared Reverb, one app per brand from brand rows
  (ADR-0025), per-brand origins enforced

Core: Registration, Admission, Commerce, Content — **invoice-settlement
chain shipped**: ADR-0017 price declaration on RegistrationType,
gate-dependent `RegistrationSettlementRequested`, Commerce order/lines with
ADR-0030 VAT treatment (reverse charge, §19 UStG), gapless per-brand
invoice numbers, hybrid ZUGFeRD PDF/A-3 archival (ADR-0031), manual
payment booking in the operator console, and `OrderPaid` driving
`on_payment` confirmation. Remaining for the exhibitor definition-of-done
item: Messaging delivery of the invoice document.
Modules: Resources, Competition (absorbs LanBrackets per ADR-0019 and owns
the `Discipline` abstraction per ADR-0024), GameOps, Staffing

Definition of done: our own events (SXLan, Xpertise) run end-to-end on
Konventix, including invoice-settled exhibitor registrations, entrance
scanning, seat/resource booking, shift planning, and tournaments.

## Phase 3 — post-release

Community, Agenda, Sponsoring, Newsletter, Extensibility (external
webhook/OAuth API only after internal contracts are stable), BFSG audit pass,
optional multi-operator groundwork.

## Standing constraints

- BFSG accessibility: token contrast validation from the start (ADR-0008).
- No fund flows through the platform, ever (ADR-0007).
- Module status is tracked in each module's `TODO.md`, updated in the PR that
  changes the code — no generated dashboards.
