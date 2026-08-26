# ADR-0045: Modules declare default role sets; sync materializes them per brand and never rewrites a touched role

**Status:** accepted

## Context

ADR-0013 made roles data and capabilities enums. The one role any code
creates is "Operator Admin", built by granting every permission row that
exists; a brand gets no roles until someone assembles them from twelve enums
one capability at a time. The demo seeder hardcodes three brand roles by
prefix-matching permission names — the shape of the need, in the wrong
place. LanCore's failure was the opposite: roles in a static map, so adding
one meant a deploy and editing one was impossible.

## Decision

**A module declares its defaults in code, beside its `Permission` enum;
`permissions:sync` and module activation materialize them per brand as
ordinary, editable rows; a row the operator has since touched is never
rewritten.**

- `ModuleServiceProvider::defaultRoles()` returns `DefaultRole` values: a key
  (`registration.desk`), a name, and the module's own capabilities. A module
  never names another module's enum; Platform's "Brand Admin" is declared as
  *everything* and expanded against the permission catalogue.
- `roles.default_key` records origin; `roles.customized_at` is stamped by the
  editor on any operator write. Sync rewrites name and grants of a default
  whose `customized_at` is null (so a capability added to an enum reaches
  every untouched brand) and leaves a customized one alone. A name collision
  with an operator-made role is reported and skipped, never hijacked.
- Materialization runs from `permissions:sync` for every brand, from module
  activation for the activated module (ADR-0003: a module switched on later
  gets its roles then, not on the next deploy), and from brand creation for
  the always-active modules.
- Brand scope only. The operator scope keeps its single seeded role.

### Alternative rejected

**Compare grants against the declared set to detect edits.** A brand whose
operator happened to grant exactly the default set would read as untouched
and be renamed under them; intent has to be recorded, not inferred.

## Consequences

- `permissions:sync` must still run in deploys, and now touches roles; its
  output says what it created, updated and skipped per brand.
- There is no role-delete route yet. When one lands it must leave a tombstone
  (a `default_key` row with a deleted marker), or a deleted default returns
  on the next sync — config pretending to be data.
- Default sets are a starting point; a brand whose operator wants different
  ones edits them and is left alone from then on.
