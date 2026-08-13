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

- [x] Slice 12 (domain) — entries, publishing, announcements, comments.
      `ContentEntry` with a typed discriminator rather than a table per kind:
      news, a page and an announcement differ in presentation, not in
      substance. Three decisions are visible in what is *absent* — no revision
      table (D-CNT-09), no locale column (D-CNT-06), and an `audience` of only
      public/login-required (D-CNT-07, since participant-gating would couple
      Content to Registration).
      `Scheduled` is a distinct state, not a published row with a future date,
      so "is this live" needs no arithmetic and an entry unpublished before
      its date cannot silently appear when the date arrives — there is a test
      for exactly that.
      Announcements emit `AnnouncementPublished` **alongside**
      `ContentEntryPublished`: an announcement is still an entry, and a
      consumer listing content should not need notification semantics to see
      it. The payload carries urgency and audience only — intent, never a
      channel or a time (D-CNT-08) — asserted by reflecting over the payload.
      Comments require an account (D-BATCH-7), so `author_user_id` is not
      nullable; that is the decision, not an oversight. Rejecting and hiding
      stay distinct because a moderator needs to know which they did.
      `LatestNewsBlock` contributes to **Platform's** block registry, the same
      way Event contributes its hero block — there is no second block system.
      It resolves entries at render time, or a page would freeze whichever
      articles were newest the day an editor dropped it in.

- [x] Write `SPEC.md` — purpose, ownership boundary, event contract.

<!-- Move completed items here with the PR that finished them. -->
