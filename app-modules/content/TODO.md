# Content — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next

- [ ] No preview for an entry that is not live. The console links to the
      public page only once it is published, because that is the only URL
      that exists — reviewing a draft means publishing it. A signed preview
      route on the brand's domain would close this; it needs a decision on
      who may hold such a link and for how long.

- [ ] Bodies are plain text rendered with preserved line breaks. Rich text
      (or Markdown) needs a sanitizer chosen first: the public entry body is
      the one field on the site that would become an XSS sink.
- [ ] Event association is a raw ULID field in the editor: Content may not
      import Event's models (ADR-0004), so an event picker needs an event read
      model here, fed by Event's contract events.
- [ ] Reporting a comment is not deduplicated per reader — a second click
      raises the counter again. Accepted while the counter is only a queue
      ordering hint; revisit if it is ever used as a moderation threshold.

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

- [x] The entry list and the moderation queue name their authors. Both read
      `content_authors` — one keyed lookup per page, never one per row — and
      fall back to "Participant" exactly as the public page does for an
      account the projection has not seen. The queue's author cell is a real
      link to Identity's account page, by route name: a URL, not a model.
      In the same pass the queue's state filter moved onto the declared
      filter contract and its brand filter onto the console selection, so no
      console list hand-rolls a select any more.

- [x] The entry list defaults its brand filter from the console selection, and
      the operator clears it per list with `brand=all` — the type and state
      selects moved onto the shared toolbar in the same pass, so the page
      hand-rolls no filter of its own any more. No event filter yet: that
      still waits on the event read model this file already owes.

- [x] Author backfill closed: `identity:replay-profiles` re-emits
      `UserProfileUpdated` for every account, so an account that existed
      before `content_authors` did — and read as "Participant" — now
      projects a real byline. Identity's command, not Content's; Messaging's
      recipient read model had the same gap and the one replay closes both.

- [x] `/news` is in the brand topbar. Readers used to arrive only through the
      latest-news block, the banner, or a link somebody sent them — the
      shell mechanism this was waiting on is `NavigationRegistry`'s
      `AREA_BRAND`, so Content registers the entry from its own provider and
      the topbar keeps knowing nothing about news.

- [x] Public pages — `/news` listing, `/news/{slug}` detail with comments,
      the urgent-announcement banner, and the `LatestNewsBlock` Vue component
      that had been registered with no component behind it (a page carrying
      the block rendered nothing). Two rules run on every read: live-only via
      `scopeLive`, and audience — a `logged-in` entry is absent from the list
      for a guest and **404s** by slug rather than redirecting to login,
      because a redirect confirms the slug exists and D-CNT-07 makes audience
      a visibility rule, not a paywall. The banner carries urgent
      announcements only and fetches itself once per browser page load, the
      way the notification bell does, so no public controller carries
      announcement plumbing. `PostComment`'s refusal on a closed section
      surfaces as a form error: from the reader's side that is an ordinary
      race, not a fault.

- [x] Author read model (`Models\Author`, `Listeners\ProjectAuthor`) —
      display names for bylines and comments, projected from Identity's
      `UserRegistered`/`UserProfileUpdated` (ADR-0004). Display name only: a
      byline needs a name, not an address, and storing contact data this
      module never shows would only hand Compliance another table to sweep.

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

- [x] "View" link from the console to the entry's own page, on both the index
      and the editor. The console resolves no brand (ADR-0029), so the link is
      built from the brand's primary domain via Platform's new
      `Brands\BrandUrl`, not from `route()`. Shown only for a live entry: a
      draft has no public page, and a link that 404s teaches an editor to
      distrust the button.

<!-- Move completed items here with the PR that finished them. -->
