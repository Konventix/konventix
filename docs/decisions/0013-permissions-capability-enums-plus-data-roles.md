# ADR-0013: Capability enums in code, role→permission mapping in data

**Status:** accepted

## Context

LanCore had 20+ per-domain `Permission` enums plus a static `RolePermissionMap`,
with roles hardcoded (lessons-from-lancore #9). Adding a role meant a deploy.

Konventix needs the opposite split. The *capability catalog* belongs to the
module that owns the behaviour — it is code, it ships with the feature, and it
must be greppable and refactorable. The *role definitions* and their permission
grants are operational data, differ per brand, and must be editable at runtime
by an operator (ADR-0001: "per-brand roles/permissions must be data-driven, not
code-driven").

## Decision

**Package: `spatie/laravel-permission`, with `teams` enabled and the team key
set to `brand_id`.**

- Each module keeps `src/Enums/Permission.php` as the authoritative capability
  catalog for what it owns. Modules never reference another module's enum.
- Permission *rows* are not hand-managed. A `permissions:sync` command walks the
  registered modules' enums and upserts the corresponding rows. The enum is the
  source of truth; the table is a projection of it.
- Roles and role→permission assignments are data, created per brand. The
  brand-scoped `team_id` gives us per-brand roles for free, and matches how
  `BelongsToBrand` (ADR-0010) already scopes everything else.
- Authorization is checked through Laravel's Gate as usual; policies live in
  the owning module's `src/Policies/`.

### Alternatives considered

**`silber/bouncer`.** Its native multi-tenant "scopes" map onto brands more
directly than spatie's teams, and it models abilities more flexibly. Rejected
because it wants to own ability creation at runtime, which fights the
enum-catalog rule — we would be fighting the library to keep capabilities in
code. Smaller community and slower release cadence than spatie's were secondary
factors.

**First-party implementation in Platform.** Would fit the enum-catalog rule
perfectly and add no dependency. Rejected for a 1–3 person team: permission
caching, wildcard grants, Gate integration, and the migration surface are a
meaningful amount of code to own and test, and the sync-from-enums approach
gets the same property from a library that is already hardened.

**`spatie/laravel-permission` without teams**, scoping manually. Rejected
outright — it recreates the hand-written `where()` mechanism that ADR-0010
exists to forbid.

## Consequences

- One dependency, in exchange for not owning permission caching and Gate wiring.
- The permissions table is derived state. Never edit it by hand; edit the enum
  and re-sync. `permissions:sync` must run in deploys.
- Spatie's permission cache is global; it must be flushed on sync, and any
  brand-context switch inside a single process (queue workers, tests) must
  reset the team context. The base TestCase does this alongside brand context.
- A capability removed from an enum leaves an orphan row and any grants that
  reference it; the sync command reports orphans rather than deleting silently,
  because a typo in an enum should not revoke access across every brand.
