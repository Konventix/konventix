# ADR-0001: Brands, not tenants

**Status:** accepted

## Context

One operator (the Verein) runs multiple public identities (SXLan, Xpertise,
IT-security conference) on their own domains. Classical multi-tenancy
(tenant isolation, per-tenant databases, tenant self-signup) was evaluated
and rejected as overkill with heavy operational, legal, and billing cost.

## Decision

Model a single **Operator** owning multiple **Brands**, each with multiple
**Events**. Brand is the scoping level: middleware resolves brand from the
request host, sessions are brand-scoped, FrankenPHP/Caddy on-demand TLS serves
arbitrary brand domains, and brand context propagates into queued jobs.

Operator data is stored in database rows (not config) so a future
multi-operator mode stays possible without schema archaeology.

## Consequences

- No tenant isolation layer; a `BelongsToBrand` global scope enforces brand
  scoping (ADR-0010).
- Per-brand roles/permissions must be data-driven, not code-driven.
- Invoice number ranges, themes, module activation, and static content are
  per-brand concerns.
