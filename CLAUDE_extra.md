# Konventix — Agent Instructions

Konventix is an event-agnostic event management platform (successor to LanCore),
built as a **modular monolith** on Laravel + Vue 3 + Inertia. One operator (a legal
entity) runs multiple **brands**, each brand runs multiple **events**. Brands are
resolved by domain at request time — this is NOT multi-tenancy.

## Before you write code

1. Read `docs/architecture/overview.md` — the system model.
2. Read `docs/architecture/module-map.md` — which module owns what.
3. Read the ADRs in `docs/decisions/` — these are settled. Do not re-litigate them
   in code. If an ADR blocks you, say so instead of working around it.
4. When working on a module, read its `SPEC.md` first and update its `TODO.md`
   in the same PR (check off what you completed, add follow-ups you discovered).
5. A feature with operational state adds a metric, a dashboard panel and, where
   it can fail silently, an alert (ADR-0059, `.ai/rules/observability.md`).
   `ObservabilityCoverageTest` checks the first two.

## Hard rules (violations are architecture bugs, not style issues)

- **No cross-module Eloquent imports.** A module never `use`s another module's
  models. Reference foreign entities by ULID. If a module needs foreign display
  data, it maintains its own read model, populated by listening to the owning
  module's events. (See ADR-0004.)
- **Events are the only public contract between modules.** Event payloads contain
  IDs and scalars, never Eloquent models.
- **Every module registers itself** via its own ServiceProvider: routes, policies,
  listeners, navigation, Pennant features, GDPR exporters. Never add module
  wiring to a central provider. (See ADR-0003.)
- **Module registration always happens at boot. Activation is a request-time
  gate per brand** via Laravel Pennant, enforced at four points: routes, nav,
  UI slots, listeners. (See ADR-0003.)
- **Every brand-owned model uses the `BelongsToBrand` trait** (global scope from
  the resolved brand context). Never write manual `where('brand_id', ...)`
  clauses as the scoping mechanism. (See ADR-0010.)
- **One `Data` object per form/endpoint** (spatie/laravel-data): it is the single
  source for validation rules, Inertia props, and generated TypeScript types.
  Never hand-write TS types for backend payloads; never build inline
  `->map(fn ...)` serialization arrays in controllers. (See ADR-0009.)
- **Frontend components reference only semantic design tokens**
  (`--color-surface`, `--color-accent`, ...), never raw palette values.
  (See ADR-0008.)
- **`Model::shouldBeStrict()` is on** in non-production. Fix N+1s, do not
  disable strictness.
- **Business logic lives in Actions** (with `DB::transaction` inside the Action),
  not in controllers. Controllers translate HTTP ↔ Actions/Data, nothing more.
- **Every model has a ULID primary key** (`HasUlids`, `$table->ulid('id')->primary()`).
  No auto-increment keys. Cross-module foreign columns are `ulid` with no
  database foreign key constraint. (See ADR-0014.)
- **Capabilities are enums, roles are data.** Each module owns
  `src/Enums/Permission.php`; roles and their grants are per-brand rows managed
  through spatie/laravel-permission with `teams` bound to `brand_id`. Never
  hand-write permission rows — edit the enum and run `permissions:sync`.
  (See ADR-0013.)

## Working conventions

- PHP 8.3+, Laravel (current major), Pest for tests. Feature tests per module
  live in the module's own `tests/` directory.
- Vue 3 `<script setup lang="ts">`, Inertia, Tailwind v4 with `@theme` tokens.
- Wayfinder-generated route/action helpers in the frontend — never hardcode URLs.
- Keep Vue pages small; extract components before a page passes ~300 lines.
- Do not generate status dashboards, progress percentages, or summary tables in
  docs. Docs state is maintained by hand or in the PR that changes the code.

## Domain language (use these terms, not the LanCore ones)

Registration (commercial act) ≠ Admission (a person's access right).
Resource (generic, time-dimensioned) replaces Seat. Staffing replaces OrgaTeam.
See `docs/architecture/module-map.md` for the full mapping.
