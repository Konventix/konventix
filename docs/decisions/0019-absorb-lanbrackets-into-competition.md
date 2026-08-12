# ADR-0019: Absorb LanBrackets into the Competition module

**Status:** accepted — supersedes ADR-0012's LanBrackets clause

## Context

ADR-0012 states that tournament trees and match logic remain in LanBrackets,
integrated via webhooks. ADR-0002 says the same "for now" and explicitly leaves
absorption open. The inventory measured what that boundary costs.

**LanCore owns no `matches` table.** There is no local match row anywhere in the
domain, only `match_result_proofs.lanbrackets_match_id` and
`orchestration_jobs.lanbrackets_match_id` — two bare external ID strings on two
otherwise unrelated tables. Every read of a match is an HTTP call.

Three of the four webhooks carry no match data at all. They are "something
changed, go look" pings, and LanCore duly goes and looks over HTTP.
`NextMatchProposer` calls `getMatches()` for every running competition on every
page load, mitigated only by a 30-second cache; `ConflictDetector` polls the same
way. The orchestration trigger depends on one of those polled reads, and its
failure handling is `catch (Throwable) { return; }` — a bracket-service blip
means matches silently never get orchestrated, with no operator signal. Delivery
is a synchronous `Http::timeout(5)->post()` with no queue, no retry and no
dead-letter, so COMP-F-017 exists as a specified requirement: an artisan command
a human runs to replay lost deliveries by hand.

The boundary is drawn through the middle of one aggregate. A competition's
matches are not a separable concern from the competition; they *are* the
competition, and the absence of a local `matches` table is the proof.

## Decision

**LanBrackets is absorbed into the Competition module.** This supersedes
ADR-0012's clause "Tournament trees/match logic remain in LanBrackets (external),
integrated via webhooks", and closes ADR-0002's "for now".

- The format engines (~3,200 LOC), the progression graph, the canvas renderer
  (~750 LOC) and the OBS overlay move into `app-modules/competition/`. About
  4,000 LOC in total: already isolated behind four contracts, already
  participant-agnostic (no game, map or title-specific field anywhere in
  LanBrackets), and covered by 34 Pest files.
- Competition owns local `Match`, `MatchSlot` and `MatchConnection` rows as
  brand-scoped ULID models under `BelongsToBrand`. `MatchScheduled` becomes a
  real state transition instead of a polled inference, which is what ADR-0012's
  event flow assumed all along.
- Deleted: the REST API (21 endpoints), `ApiToken` auth, the webhook dispatcher
  and its HMAC signing, both webhook directions, the duplicate `User` table with
  its SSO client and role-sync receiver, the `(source_system,
  external_reference_id)` mapping, and the six `lanbrackets_*` /
  `external_reference_*` columns.
- The 34 Pest files move **with** the code, not after it.
- ADR-0012 is otherwise unchanged. GameOps still owns `Deployment` and
  `MatchSession`, Competition still owns match result state, and GameOps still
  references `competition_match_id` as a plain value — absorption only turns that
  reference from a foreign string into a real local ULID.

### Alternatives considered

**Stay external, integrate as today.** Zero migration work in Phase 2, and the
service is running and tested. Rejected because "no work" is false: the identity
bridge (SSO client, role-sync webhook, local `User` table) would have to be built
a third time, now against Konventix Identity, and the webhook payloads violate
ADR-0004's contract rules and would need reshaping regardless. It also leaves two
deployments and two databases for a 1–3 person team, which is the exact cost
ADR-0002 exists to avoid.

**Absorb the data model now, the engines later.** Bring the match tables into
Competition and keep LanBrackets alive as a stateless bracket calculator called
once per stage generation. Rejected as the primary plan but retained as the
**pre-agreed fallback if Phase 2 slips**: it removes the silent-failure class
immediately and caps scope, at the price of two systems holding match structure
during the overlap and a reconciliation story nobody wants to write twice.

## Consequences

- Konventix permanently owns double-elimination and Swiss pairing correctness.
  This is not port-and-forget; it is a domain with real edge cases, and the test
  suite is the only thing between us and quietly wrong brackets.
- This is the largest single scope addition to Phase 2 in the inventory. If it
  endangers the release, the fallback above applies — not a return to webhooks.
- COMP-F-018 and COMP-F-020 (ID translation in both directions) disappear
  entirely, and COMP-F-017 goes with the webhooks: a recovery command exists only
  because the delivery it recovers exists.
- A whole failure class goes away. Match reads become database queries, so there
  is nothing left for a `catch (Throwable)` to swallow, and the next-match
  proposer and conflict detector stop making N HTTP calls per page load.
- The latent ability for a third organizer to point their own system at the
  bracket service is forfeited. Nothing in the code prevents it today and no
  second consumer exists; if it is ever wanted it returns through Extensibility,
  which is the right tier for it anyway.
- The canvas renderer must be re-themed onto semantic tokens (ADR-0008), and the
  public `share_token` overlay route must survive brand resolution, since it is
  unauthenticated.
- ADR-0012's three open questions (pool reuse between rounds, provisioning
  fallback, match session failure and heartbeat handling) are untouched by this
  and remain open.
