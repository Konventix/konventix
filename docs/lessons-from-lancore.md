# Lessons from LanCore

Findings from the LanCore code audit (Aug 2026, 940 PHP files, 398 Vue
components, 29 domains). Each maps to a Konventix rule. Agents: when in doubt,
this file explains *why* the rules in `CLAUDE.md` exist.

| # | LanCore finding | Konventix rule |
|---|---|---|
| 1 | Domain boundaries on paper only: Competition imported 25 foreign models, Shop 24, Event 21; the event-driven rule was kept exactly once (Orchestration) | ADR-0004: events are the only contract; arch test fails the build on cross-module model imports |
| 2 | `User.php`: imports from 13 domains, 30 methods, relations everywhere, plus Billable/Auditing/2FA — a god object blocking modularization | Slim Identity user; modules keep read models (`StafferProfile`, …) |
| 3 | 408-line central `AppServiceProvider` wiring all domains' events, policies, observers | ADR-0003: modules self-register; central wiring forbidden |
| 4 | 9 API Resources vs. 173 `Inertia::render`; inline `->map(fn ...)` arrays in controllers; 771-line hand-maintained `domain.ts` drifting from backend | ADR-0009: laravel-data + TS generation; hand-written payload types forbidden |
| 5 | Shared-props middleware serialized `$user->toArray()` (address, phone, Steam data) on every request, computed all permissions, ran an uncached query | Minimal shared props; lazy/deferred everything else |
| 6 | No `Model::shouldBeStrict()` anywhere — N+1s reached production unnoticed | Strictness on in non-production from commit 1 (ADR-0010) |
| 7 | Event scoping manual and forgettable: 190 `event_id` refs, 43 hand-written where-clauses, zero global scopes | ADR-0010: `BelongsToBrand` global scope, systemic |
| 8 | Fat controllers despite 170 Actions existing: CartController (483 lines) did pricing, voucher validation, dependency checks inline | Business logic in Actions with transactions; controllers translate only |
| 9 | Permissions as code: 20+ per-domain Permission enums + static `RolePermissionMap`, roles hardcoded | Capability catalog stays as enums; role→permission mapping is data, brand-scoped |
| 10 | Frontend monoliths: 1,591-line page, 1,285-line sidebar knowing all domains | Nav registered by modules via Platform; extract components before ~300 lines |
| 11 | Schemaless `settings`/`metadata` JSON blobs, magic-string access, `Record<string, unknown>` in TS | Typed value objects / casts; free-form blobs forbidden (ADR-0009) |
| 12 | `1 ticket = 1 gamer` baked into the data model; payment implicitly meant confirmed | ADR-0005: Registration ≠ Admission; confirmation_gate decouples payment |
| 13 | PSP (Cashier/Stripe) owned order truth; invoice settlement had nowhere to live | ADR-0006: own Order/Invoice entities; PSPs are adapters |

What LanCore got right (keep): Wayfinder usage was consistent; the Actions
layer existed; feature-test-heavy test suite; per-domain `Gdpr/` exporters;
the Orchestration domain's clean event-driven design (ADR-0012).
