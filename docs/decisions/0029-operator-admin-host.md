# ADR-0029: The operator admin console is a host of the Operator, not a brand

**Status:** accepted

## Context

The operator needs an admin surface spanning all brands — brand management,
user administration, later the subject-request console and message log. Every
web request currently resolves a Brand or 404s (ADR-0001), and every
brand-owned query is scoped to the resolved brand (ADR-0010). An admin console
is cross-brand by nature, so it cannot simply be a brand — and modeling it as
a pseudo-brand would give a non-brand a membership list, a Reverb app, and a
theme, while still leaving its queries scoped to the wrong thing.

ADR-0015 and ADR-0016 already commit to "operator-level capability that
legitimately spans brands" and "admin views are brand-filtered from day one";
this ADR gives those commitments a host and a mechanism.

## Decision

**The admin console lives on the Operator's own host (`operators.admin_host`,
data like brand domains), resolved into an OperatorContext instead of a brand.**

- `ResolveBrand` recognizes the admin host: it establishes `OperatorContext`
  (no brand), names the session cookie for the operator, and lets the request
  proceed. Unknown hosts still 404. The Caddy on-demand-TLS `ask` endpoint
  approves admin hosts exactly like brand hosts.
- **Brand-scope hardening:** on a web request that resolved an operator (not a
  brand), `BrandScope` constrains brand-owned queries to *nothing* instead of
  leaving them unscoped. Admin code that legitimately spans brands uses the
  greppable `withoutBrandScope()` escape hatch (ADR-0010) inside
  capability-checked Queries — a forgotten scope on the admin host now returns
  empty instead of everything.
- **The spatie team dimension is a scope id, not strictly a brand id.** The
  column stays `brand_id`; on brand hosts the resolved brand's ULID is set as
  the permission team, on the admin host the operator's ULID is. Operator
  roles are role rows scoped to the operator's ULID, assigned under that same
  scope — plain spatie semantics, no schema change, no Gate::before override.
  A brand host therefore never resolves operator capabilities, which is
  exactly ADR-0016's "admin surfaces are elsewhere" stance.
- Authentication on the admin host is ordinary Fortify auth (accounts are
  operator-global per ADR-0016). No brand membership is recorded there —
  membership records brand contact, and there was none.

### Alternative rejected

**Admin as a special Brand row** (`kind: operator`): reuses resolution,
sessions and theming unchanged, but a pseudo-brand acquires brand semantics
that are wrong for it (memberships, per-brand Reverb app, theme editor), its
queries are scoped to the pseudo-brand rather than spanning, and every
consumer of `Brand` has to learn to skip it. The reuse is superficial; the
special-casing is permanent.

## Consequences

- Operator provisioning gains an `admin_host`; the seeder sets
  `admin.localhost` for dev.
- Admin routes are registered by Platform under an `operator-host` middleware
  that 404s them on brand hosts; brand routes on the admin host hit the
  hardened scope and return empty/404 rather than leaking.
- `permissions:sync` (ADR-0013) is the prerequisite for any admin gate and
  ships with this ADR, along with the seeded "Operator Admin" role granting
  every synced capability under the operator scope.
- The queue's brand-context restore also restores the permission team id, so
  capability checks inside jobs resolve against the brand that dispatched
  them.
- Per-brand admin (a brand-scoped staff member managing only their brand)
  remains future work: same mechanism, brand-scoped roles on brand hosts —
  nothing here blocks it.
