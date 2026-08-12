# Sponsoring — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

**Post-release module.** Sponsoring is Phase 3 in `docs/ROADMAP.md`, and the
module map says "port late" even within that phase. Do not start it before
release scope ships. Keep it thin when you do.

## Next

- [ ] Nothing scheduled. Revisit when Phase 3 begins.

## Backlog (not before Phase 3)

- [ ] Confirm the boundary with Commerce (money), Resources (booth space), and
      Registration (exhibitor registrations) before modelling anything.
- [ ] Decide where sponsor logos are stored — this is unsettled platform-wide,
      not just here.
- [ ] Sketch the sponsor block type(s) this module registers with Platform's CMS.
- [ ] Define the capability catalog in `src/Enums/Permission.php`.
- [ ] First migrations: sponsors and sponsorships, ULID primary keys (ADR-0014),
      `BelongsToBrand` (ADR-0010).

## Done

- [x] Write `SPEC.md` — purpose, ownership boundary, event contract.
