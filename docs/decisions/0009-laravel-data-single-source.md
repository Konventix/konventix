# ADR-0009: laravel-data as single source for validation, props, and TS types

**Status:** accepted

## Context

LanCore hand-built every form and request: 9 API Resources vs. 173 Inertia
renders, inline `->map(fn ...)` serialization in controllers, and a 771-line
hand-maintained `domain.ts` that drifted from the backend. This was the single
largest source of repetitive work.

## Decision

- Every form/endpoint payload is a `spatie/laravel-data` Data object living in
  the owning module's `src/Data/`.
- One Data object provides: validation rules, Inertia props shape, form
  defaults, and the generated TypeScript type
  (spatie/typescript-transformer, generated in CI and committed).
- Wayfinder-generated route/action helpers cover URLs; hand-written frontend
  URL strings are forbidden.
- Hand-written TS types for backend payloads are forbidden. Free-form
  `Record<string, unknown>` blobs (LanCore's `settings`/`metadata` columns) are
  replaced by typed Data/value objects; a JSON column is acceptable only behind
  a typed cast.

## Consequences

- Controllers shrink to HTTP ↔ Action/Data translation.
- Type drift between backend and frontend becomes a CI failure, not a runtime
  surprise.
- Inertia remains the SPA layer; no separate REST API is built for the
  first-party frontend. An external API (Extensibility) is a later, separate
  contract.
