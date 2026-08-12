# Content — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next (Phase 1 PoC — content types plus the block types Platform's page CMS needs)

- [ ] Model `ContentEntry` as **one** publishing model with a content type
      discriminator, slug, publication state, and publish window. ULID primary
      key, `BelongsToBrand`, optional `event_id` as a plain ULID column.
- [ ] Define content types as typed configuration, not a schemaless `settings`
      blob (ADR-0009); news and announcement are the first two.
- [ ] Register Content's block types with Platform's block-based page CMS so a
      brand's static pages can embed a news listing — this is part of the
      Phase 1 block-based static pages proof.
- [ ] Build the publish/unpublish/schedule Actions and emit
      `ContentEntryPublished`, `ContentEntryScheduled`,
      `ContentEntryUnpublished`.
- [ ] Public listing and detail pages, with one `Data` object per endpoint
      driving validation, Inertia props, and the generated TS type; components
      reference semantic design tokens only (ADR-0008, ADR-0009).
- [ ] Define the capability catalog in `src/Enums/Permission.php`, keeping
      publishing separate from authoring.

## Backlog (Phase 2 — release scope)

- [ ] Comments with a moderation queue, reporting, and the moderation record;
      make "comments allowed" a per-brand setting rather than a module gate.
- [ ] Taxonomy (categories and tags) with filtered listings and feeds.
- [ ] Author read model fed by Identity events; the GDPR handler in
      `src/Gdpr/` covering authored entries and comments.
- [ ] `AnnouncementPublished` consumed by Messaging, once the announcement
      urgency and audience contract in `SPEC.md` is settled.
- [ ] Media handling for entry attachments per ADR-0035 (decision log Round 6,
      D-CNT-05): reference Platform's brand-scoped media library by ULID
      instead of Content's own attachment storage.
- [ ] Register navigation entries with Platform.
- [ ] Single-locale-per-brand content model per decision log Round 6
      (D-CNT-06) — no translation columns or per-entry locale variants; a
      translation layer stays a future platform-wide decision, not something
      to build room for now.
- [ ] Audience gate for entries — public or login-required, per decision log
      Round 6 (D-CNT-07). Participant-gating stays deferred; do not couple
      this to Registration state.

## Done

- [x] Write `SPEC.md` — purpose, ownership boundary, event contract.

<!-- Move completed items here with the PR that finished them. -->
