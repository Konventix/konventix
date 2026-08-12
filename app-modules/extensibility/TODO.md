# Extensibility — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

**Post-release module with a hard precondition.** Extensibility is Phase 3 in
`docs/ROADMAP.md`, and beyond that it must not begin until the internal module
contracts are stable — publishing an external contract early is the specific
failure the module map and `docs/architecture/overview.md` warn against. Do not
start it before both conditions hold.

## Next

- [ ] Nothing scheduled. Revisit when Phase 3 begins *and* internal event
      contracts have stopped moving.

## Backlog (not before Phase 3)

- [ ] Write an ADR for the external API: style, versioning promise, deprecation
      policy. Publishing a contract is a commitment.
- [ ] Map OAuth scopes onto the modules' capability enums (ADR-0013) without
      duplicating or redeclaring them.
- [ ] Specify webhook delivery: signing, secret rotation, retries and backoff,
      dead-lettering, and its own queue name.
- [ ] Confirm against ADR-0011 exactly what a proprietary plugin may touch, so
      the licensing line stays defensible.
- [ ] Define the (narrow) capability catalog in `src/Enums/Permission.php`:
      manage apps, manage subscriptions, read the delivery log.

## Done

- [x] Write `SPEC.md` — purpose, ownership boundary, event contract.
