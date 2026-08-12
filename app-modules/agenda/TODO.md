# Agenda — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

**Post-release module.** Agenda is Phase 3 in `docs/ROADMAP.md`. Do not start it
before release scope ships. The backlog below is a starting point for that
phase, not work that is waiting.

## Next

- [ ] Nothing scheduled. Revisit when Phase 3 begins.

## Backlog (not before Phase 3)

- [ ] Answer the `SPEC.md` open questions — personal agenda ownership, and how
      mirrored Competition matches work — before modelling anything.
- [ ] Decide how an agenda item names its location when Resources is inactive
      for the brand.
- [ ] Define the capability catalog in `src/Enums/Permission.php`.
- [ ] First migrations: agenda items and tracks, ULID primary keys (ADR-0014),
      `BelongsToBrand` (ADR-0010).
- [ ] Register navigation with Platform and add the `src/Gdpr/` exporter.

## Done

- [x] Write `SPEC.md` — purpose, ownership boundary, event contract.
