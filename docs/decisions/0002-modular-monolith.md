# ADR-0002: Modular monolith instead of satellite apps

**Status:** accepted

## Context

LanCore grew satellite apps (LanBrackets, LanHelp, …) as separate deployments.
This multiplied deployment surface, auth integration, and data synchronization
cost for a 1–3 person team.

## Decision

Konventix is one Laravel application. Features live as modules in
`app-modules/*` (InterNACHI/modular-style). Runtime separation is reserved for
genuine offline requirements only — e.g. the venue entrance scanning client,
which must validate tickets without connectivity to the main app.

~~Tournament tree/match logic (LanBrackets) remains an external service for now,
integrated via webhooks; long-term it may be absorbed into the Competition
module.~~ **Superseded by ADR-0019:** the "long-term" is now. LanBrackets is
absorbed into Competition, and the webhook integration is deleted rather than
maintained.

## Consequences

- One image, multiple roles (web / queue workers by queue name / scheduler).
- Module boundaries must be enforced by convention + tooling (ADR-0004),
  since the runtime enforces nothing.
- External extensibility (webhooks/OAuth) is a separate, later tier.
