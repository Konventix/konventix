# Community — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

**Post-release module.** Community is Phase 3 in `docs/ROADMAP.md`. Do not start
it before release scope ships. The backlog below is a starting point for that
phase, not work that is waiting.

## Next

- [ ] Nothing scheduled. Revisit when Phase 3 begins.

## Backlog (not before Phase 3)

- [ ] Settle chat transport (polling, websockets, external service) — it changes
      the deployment surface and deserves an ADR.
- [ ] Decide how community read models are backfilled when a brand enables the
      feature late.
- [ ] Define the capability catalog in `src/Enums/Permission.php`, including a
      moderation dimension.
- [ ] Add the `src/Gdpr/` exporter early — chat messages and awards are personal
      data, and retention has to be answered together with Compliance.
- [ ] First migrations: brand-scoped (not event-scoped), ULID primary keys
      (ADR-0014).

## Done

- [x] Write `SPEC.md` — purpose, ownership boundary, event contract.
