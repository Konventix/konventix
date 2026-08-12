# GameOps — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next

- [ ] Implement per the amended ADR-0012 (decision log Round 6, D-GOP-01
      through D-GOP-03): pool reuse configurable per server pool
      (fresh-per-match default, reuse mode needs a `MatchHandler` reset
      hook), bounded-retry-then-desk-handoff on failed provisioning, and
      provider-status-plus-heartbeat session liveness emitting
      `SessionUnhealthy` past threshold. They shape the schema and the job
      design, so they come before the migrations.
- [ ] Declare the contracts in `src/Contracts/`: `GameServerProvider`
      (provision/destroy/getStatus), `MatchHandler`
      (configure/start/getState/getResult), `MatchHandlerResolver`, and
      `DeploymentStrategyResolver` — with fake implementations for tests before
      any real driver exists.
- [ ] Add migrations for `games`, `game_modes`, `server_pools`, `deployments`,
      `match_sessions`, and `player_game_accounts`: ULID primary keys,
      `BelongsToBrand`, and `competition_match_id` as a `ulid` column with no FK
      constraint (ADR-0012, ADR-0014).

## Backlog

- [ ] Define the capability catalog in `src/Enums/Permission.php` (manage games,
      manage pools, configure credentials, view deployments, operate
      deployments, operate sessions) and make `permissions:sync` pick it up
      (ADR-0013).
- [ ] Implement the `MatchScheduled` listener and the provisioning pipeline as
      Actions and queued jobs: resolve handler and strategy, provision or claim
      from the pool, configure, start, emit `MatchSessionLive`. Runs on its own
      queue with an extended timeout, gated on the `game-ops` feature, and
      idempotent per `competition_match_id`.
- [ ] Implement the teardown path on `MatchFinished` (and the proposed
      `MatchCancelled`), emitting `DeploymentDestroyed` including whether the
      deployment returned to its pool.
- [ ] Write the first two drivers behind the contracts: Pelican Panel for
      `GameServerProvider` and the TMT2 API client for `MatchHandler`, with
      brand-scoped encrypted credentials and recorded HTTP fixtures in tests.
- [ ] Build the operations surface: deployments and match sessions with their
      state, connection details, and manual retry or force-teardown actions,
      backed by `Data` objects (ADR-0009); register nav entries with Platform.
- [ ] Add `src/Gdpr/` handling for `PlayerGameAccount` and session logs, and an
      arch test proving this module imports no Competition model.
- [ ] Feature test for the Phase 2 definition of done: `MatchScheduled` through
      to `MatchSessionLive` and back to `DeploymentDestroyed` against the fake
      provider and handler, plus a test proving the listeners short-circuit when
      the `game-ops` feature is off for the brand.

## Done

<!-- Move completed items here with the PR that finished them. -->

- [x] Write `SPEC.md` — purpose, ownership boundary, event contract.
