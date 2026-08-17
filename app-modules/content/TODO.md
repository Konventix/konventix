# Content — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next

- [ ] Public listing and detail pages, with one `Data` object per endpoint
      driving validation, Inertia props, and the generated TS type; components
      reference semantic design tokens only (ADR-0008, ADR-0009).
- [ ] `LatestNewsBlock` names the Vue component `content/blocks/LatestNewsBlock`
      but no such component exists, so a page carrying the block renders
      nothing. It lands with the public pages above (the block links into
      them).
- [ ] Event association is a raw ULID field in the editor: Content may not
      import Event's models (ADR-0004), so an event picker needs an event read
      model here, fed by Event's contract events.
- [ ] No editor read model either — the moderation queue and the entry list
      show bare author ULIDs, the same gap the role and media screens have
      (Platform TODO: identity read model).

## Backlog (Phase 2 — release scope)

- [ ] "Comments allowed" is per entry today; make it a per-brand default the
      entry can override, and decide where pre-moderation is switched on —
      `PostComment` already takes the flag, but nothing sets it.
- [ ] Taxonomy (categories and tags) with filtered listings and feeds.
- [ ] Author read model fed by Identity events; the GDPR handler in
      `src/Gdpr/` covering authored entries and comments.
- [ ] Media handling for entry attachments per ADR-0035 (decision log Round 6,
      D-CNT-05): reference Platform's brand-scoped media library by ULID
      instead of Content's own attachment storage.
- [ ] Single-locale-per-brand content model per decision log Round 6
      (D-CNT-06) — no translation columns or per-entry locale variants; a
      translation layer stays a future platform-wide decision, not something
      to build room for now.
- [ ] Audience gate for entries — public or login-required, per decision log
      Round 6 (D-CNT-07). Participant-gating stays deferred; do not couple
      this to Registration state.

## Done

- [x] Operator console (ADR-0029) — editorial index, entry editor, lifecycle
      endpoints, moderation queue, and the two navigation entries. The screen
      split follows the domain, not a layout preference: `UpdateEntry` never
      touches `state` or `published_at`, so saving a typo fix in a live
      announcement cannot notify everyone a second time, and publishing is one
      button per Action. `transitions` is computed server-side so the console
      cannot offer a move the Actions would refuse. Authoring
      (`content.manage-entries`) and publishing (`content.publish-entries`)
      are enforced separately — there is a test that an author may edit and
      may not publish. The moderation queue opens on "needs attention"
      (pending plus anything reported) rather than every comment ever posted,
      and keeps reject and hide apart the way `ModerateComment` does.

- [x] `AnnouncementPublished` consumed by Messaging (slice 13e:
      `SendAnnouncementNotifications` over the `content.announcements` topic).

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
