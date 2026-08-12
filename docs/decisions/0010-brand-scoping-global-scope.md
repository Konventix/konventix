# ADR-0010: Systemic brand scoping via global scope

**Status:** accepted

## Context

LanCore scoped queries to events manually: 190 `event_id` references, 43
hand-written `where('event_id', ...)` clauses, zero global scopes. Every
forgotten clause is a data leak between events — with brands, between brands.

## Decision

- Every brand-owned model uses a `BelongsToBrand` trait: a global scope reading
  the middleware-resolved brand context, plus automatic `brand_id` assignment
  on create.
- Manual `where('brand_id', ...)` as the scoping mechanism is forbidden;
  explicit cross-brand queries require a named, greppable escape hatch
  (`Model::withoutBrandScope()` behind an operator-level permission).
- Queued jobs carry the brand context; workers restore it before handling.
- `Model::shouldBeStrict()` (lazy-loading prevention included) is enabled in
  non-production from the first commit.

## Consequences

- Brand isolation is a property of the persistence layer, not of developer
  discipline.
- Tests must always establish a brand context; the base TestCase provides one.
