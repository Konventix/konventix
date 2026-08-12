# Newsletter — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

**Post-release module.** Newsletter is Phase 3 in `docs/ROADMAP.md`. Do not
start it before release scope ships. The backlog below is a starting point for
that phase, not work that is waiting.

## Next

- [ ] Nothing scheduled. Revisit when Phase 3 begins.

## Backlog (not before Phase 3)

- [ ] Write the `NewsletterProvider` contract first, the Listmonk driver second.
      No provider concept may leak past the contract.
- [ ] Settle where the opt-in consent record lives — Compliance or here — before
      any schema exists.
- [ ] Decide the reconciliation policy when local and provider state disagree.
- [ ] Define the capability catalog in `src/Enums/Permission.php` and add the
      `src/Gdpr/` exporter.
- [ ] First migrations: lists and subscriptions, ULID primary keys (ADR-0014),
      `BelongsToBrand` (ADR-0010).

## Done

- [x] Write `SPEC.md` — purpose, ownership boundary, event contract.
