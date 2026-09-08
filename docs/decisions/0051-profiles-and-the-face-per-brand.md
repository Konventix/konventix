# ADR-0051: A person has one handle and one profile, and wears a face per brand

**Status:** accepted

## Context

Slice 25 gives accounts a public shape: a handle, a profile, a page. The
ROADMAP already settled most of it — Identity's `User` stays slim
(ADR-0004 names the LanCore god object as the anti-pattern), the public
page is Community's and pulls that module forward from Phase 3, the Steam
avatar is a copy and never a hotlink, and the page is visible to signed-in
members of the brand only. Two questions it deliberately left to this ADR.

**Who owns the avatar?** Media rows are brand-owned (`media_items.brand_id`
is a constrained FK, and the media admin, the disks and the usage tracking
all assume it) while accounts span the operator (ADR-0016). An avatar is
therefore either the first brandless media row the system has ever held,
or it is not operator-wide at all.

**Where do usernames come from?** No username field exists anywhere —
`Fortify::username()` is the email login column. The handle is unique
operator-wide because the account is (ADR-0016), and every existing
account lacks one.

A fact that changes the second answer: the platform is pre-release. v0.1.0
exists as an artifact, no production deployment holds a single real user,
and every populated database is a seeder's work. Machinery for migrating
real users to handles would be machinery for users who do not exist.

## Decision

1. **The avatar and banner live on the brand membership.** Nullable
   `avatar_media_id` and `banner_media_id` on `brand_memberships`,
   referencing brand-owned `media_items` rows by ULID — no cross-module
   FK constraint (ADR-0014), and no change to media's brand ownership:
   the person uploads a face *to a brand*, and it lands in that brand's
   library like any other upload. A person may want a different face at a
   LAN than at a trade fair — the ROADMAP's own argument — and Community's
   SPEC already describes its profile read model as per-brand, so the
   write model now matches the read model instead of fighting it. The
   topbar shows the resolved brand's avatar; the operator console resolves
   no brand (ADR-0029) and shows initials.

2. **The profile-of-record on `User` is text, and operator-wide:**
   `username`, `tagline` (hard cap 140 characters), `status_emoji` (one
   emoji grapheme), `social_links` (a validated map over an allowlist —
   steam, discord, twitch, youtube, x, instagram, website — keys fixed,
   values shape-checked per platform, `website` a plain URL). These are
   what the person says about themselves everywhere; the images are what
   they show a brand's community. `User` stays slim: no counts, no
   activity, no per-brand anything.

3. **Usernames are taken for granted.** `users.username` ships NOT NULL,
   unique operator-wide and case-insensitive (unique index on
   `lower(username)`; stored as typed, compared folded). Factories
   generate unique handles, `DatabaseSeeder` and `konventix:seed` assign
   them, and the migration backfills any existing row from a slug of the
   display name with a numeric suffix on collision — which, pre-release,
   is dev fixtures and nothing else. There is no claim flow, no
   at-next-login gate, and no "profile disabled until named" state; that
   machinery would serve users who do not exist yet, and the day real
   users predate a feature needing a handle, the backfill-vs-claim
   question can be reopened with actual stakes. Format:
   `^[a-z0-9](?:[a-z0-9-]{1,30}[a-z0-9])$` (3–32 chars, lowercase,
   digits, inner hyphens), with a reserved list (`admin`, `konventix`,
   `operator`, `api`, `www`) refused at validation.
   > Amended 2026-09-02 (#143): registration asks for the handle.
   > `RegisterData` and `CompleteSocialSignupData` require `username` under
   > the same rules as the profile form (`ProfileValidationRules::
   > usernameRules()`); the generator stays for factories, seeders and the
   > migration backfill.

4. **The Steam avatar is copied, once.** "Use my Steam avatar" fetches
   the linked identity's `avatar_url` into the *current brand's* media
   library and points the membership at the copy. A profile picture that
   changes when Steam's CDN does is not the person's.

5. **The shared `auth.user` prop becomes the minimal shape Identity's SPEC
   promised** — ULID, display name, avatar URL, locale — before any
   avatar reaches the topbar. The avatar URL is resolved from the current
   brand's membership server-side; the whole model stops being
   serialized. A fifth field joined it in #243: `timezone`, the zone every
   system timestamp is rendered in, `null` for a person who has chosen
   none and reads on their own device's clock. The rule is the ceiling, not
   the count: anything a page needs beyond these is that page's own prop.

6. **Two events, matching the two scopes.** `UserProfileUpdated` widens
   with `username`, `tagline` and `statusEmoji` — operator-wide facts in
   the operator-wide event, replayable via `identity:replay-profiles`
   (P1), which is what lets Content's authors and Messaging's recipients
   learn handles for accounts created before the projection. The images
   travel in a new brand-scoped event,
   `BrandMembershipProfileUpdated(userId, brandId, avatarUrl, bannerUrl,
   updatedAt)`, which Community's per-brand profile projects from — and
   the replay command emits both, so a projection built after the fact
   still learns every face. The
   ROADMAP's sentence "every profile field added below has to travel in
   this payload" predates the per-brand decision; splitting the payloads
   is that sentence applied to the ownership the images actually have.

7. **The public page is `u/{username}` on the brand host, Community's,
   for signed-in members of that brand.** It renders Community's profile
   read model (display name, avatar, member since) plus the
   profile-of-record, with comments through slice 22's generic
   reaction/comment subject. Filling a field is choosing to show it: an
   empty tagline renders nothing, and there is no second per-field
   visibility system to configure and forget. A non-member — or any
   signed-out visitor — gets a 404, not a teaser: whether a person is a
   member of a brand is itself personal data.

8. **Community is pulled forward for exactly this surface.** The module
   skeleton exists with its SPEC marked provisional; the profile read
   model section graduates to settled, chat/presence/achievements stay
   Phase 3. Groups and clans are the next Community slice and are not
   designed here.

9. **GDPR follows the shapes.** The new `User` fields join Identity's
   export and are tombstoned on anonymization (`username` becomes
   `deleted-<ulid>` — unique and meaningless, because the column is NOT
   NULL and the tombstone must not collide; a system write exempt from
   the input format's length cap, the same way the anonymized email uses
   the reserved `.invalid` domain). Membership avatars vanish
   with the membership rows ADR-0050 already deletes operator-wide; the
   media rows they pointed at are brand-owned uploads that the orphan
   sweep reclaims once usage rows land (Platform's TODO). Community's
   profile read model scrubs via its own GdprHandler when the module's
   first real data lands.

## Alternatives rejected

- **One operator-wide avatar, `media_items.brand_id` nullable.** Touches
  the media subsystem's core assumption for one consumer: the brand-scoped
  media admin, the per-brand disks (ADR-0043) and the usage tracking would
  all need a "brandless" branch, and the operator gains storage it never
  chose to host. Rejected as the expensive shape of a preference some
  users will not even have.
- **Hybrid: operator default plus per-brand override.** Both schema
  changes, both storage questions, a three-step resolution — for a
  distinction the membership-only shape already expresses (upload the
  same face twice and you have an operator-wide avatar in practice).
- **Claimed-on-first-need usernames.** The correct answer for a platform
  with users; this one has none. A nullable handle column, claim prompts
  and 404-until-claimed pages are complexity spent guarding people who do
  not exist against a default nobody will ever have received.
- **Backfill from display names in production.** The variant that would
  matter with real users — handing people identities they never chose —
  is exactly the one the pre-release state lets us skip judging.
- **Hotlinking the Steam avatar.** A third party's CDN deciding what a
  person looks like on every brand, and a broken image the day Steam
  rotates the URL.

## Consequences

- `brand_memberships` gains two nullable media ULIDs; `users` gains four
  columns, one NOT NULL. Factories, `DatabaseSeeder`, `konventix:seed`
  and every test creating users adjust in the same slice.
- `identity:replay-profiles` is re-run after deploy so existing read
  models learn the widened payload; that replay was built for exactly
  this (P1).
- The `auth.user` prop change is a breaking change for any frontend code
  reading fields beyond the four; the slice fixes those reads.
- `u/{username}` is Community's first routed page; the module's Pennant
  gate must be active for it, and brands that keep Community off simply
  have no profile pages — that is the gate working, not a bug.
- The reserved-username list is validation, not migration: seeded dev
  data must not use reserved names, and the backfill suffixes around
  them.
