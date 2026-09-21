# Content — Specification

> Stable document. Describes what this module *is*. Task tracking lives in
> `TODO.md`.

## Purpose

Content owns **editorial publishing**: the things a brand writes, dates,
publishes, and later archives — news posts, announcements, and whatever
further content types a brand needs. LanCore had News, Announcement, and
Publishing as three separate domains repeating the same draft/publish/archive
machinery; Konventix has **one publishing model with multiple content types**
(`docs/architecture/module-map.md`), so adding a type is data and configuration
rather than a fourth copy of the same lifecycle. Content also owns **comments**
and their moderation.

The boundary that matters most: Content owns **time-ordered editorial entries**.
It does **not** own the brand's static pages — those are Platform's block-based
CMS. Content registers block types with Platform (for example a "latest news"
block) rather than reimplementing page composition.

## Owns

- **`ContentEntry`** — one publishing model carrying a content type
  discriminator (news, announcement, and further brand-defined types), a title,
  a slug, a body, and a teaser.
- **Content types** — the configuration that says which types a brand publishes
  and how each behaves (whether it accepts comments, whether it notifies, how
  it is listed).
- **Publication lifecycle** — draft, scheduled, published, unpublished,
  archived — plus the publish window and the scheduling that moves an entry
  through it.
- **Authorship and editorial attribution**, referencing the author by ULID and
  displaying from a read model.
- **Taxonomy** — categories and tags used to organize and filter entries.
- **Optional event association** — an entry may be scoped to a specific event
  by ULID (an announcement about SXLan) or be brand-wide.
- **Comments and moderation** — posting, approval queues, rejection, hiding,
  reporting, reactions, and the moderation record.
- **Media attached to an entry** — inline images and downloads belonging to
  that entry, until a shared media library exists in Platform (see open
  questions).
- **Block types this module contributes to Platform's page CMS**, so brand
  static pages can embed news listings and announcements.
- **Public listing and detail routes** for a brand's editorial content, and the
  feeds derived from them.

## Does not own

| Nearby concept | Owned by |
|---|---|
| Static brand pages, the block registry, and page composition | **Platform** — Content registers block types with that CMS; it does not build a second one |
| Theming, design tokens, and how an entry is styled | **Platform** (ADR-0008) — Content supplies semantic markup, never palette values |
| Navigation structure and menus | **Platform**'s navigation registry — Content registers its entries there |
| The programme, schedule, sessions, and talks | **Agenda** — a talk description is programme data, not an editorial post |
| Newsletter campaigns, subscriber lists, and sending | **Newsletter** for campaigns, **Messaging** for delivery. Content publishes; it never sends |
| Push or in-app notification of an announcement | **Messaging** — Content emits an event, Messaging decides how it reaches people |
| Chat, activity feeds, and social interaction between participants | **Community** — comments on an editorial entry are Content's, conversation between participants is not. A like on an editorial comment is Content's; Community reacts through the same `(module, entity_type, entity_id)` subject triple (slice 26) |
| Author accounts, profiles, and authentication | **Identity** — Content keeps an author read model (ADR-0004) |
| Editorial permissions granted per brand | Capabilities are declared here; the **roles** that grant them are per-brand data (ADR-0013) |
| Event dates and metadata an entry refers to | **Event** — Content keeps a read model for display |
| Sponsor logos and paid placements | **Sponsoring** |
| Retention rules and deletion of authored content | **Compliance** orchestrates; Content contributes its own `src/Gdpr/` handler for authored entries and comments |

## Public contract (events)

Events are the only way other modules may react to this one (ADR-0004).
Payloads carry ULIDs and scalars — never Eloquent models.

All rows below are **proposed**: no code exists yet, and names are expected to
firm up as the first content types are built.

| Event | Emitted when | Payload |
|---|---|---|
| `ContentEntryPublished` (proposed) | An entry becomes publicly visible, whether immediately or by schedule | `entry_id`, `brand_id`, `content_type`, `slug`, `title`, `author_user_id`, `event_id` (nullable), `published_at` |
| `ContentEntryScheduled` (proposed) | An entry is queued for future publication | `entry_id`, `brand_id`, `content_type`, `scheduled_for` |
| `ContentEntryUpdated` (proposed) | A published entry is edited | `entry_id`, `brand_id`, `updated_by_user_id`, `updated_at` |
| `ContentEntryUnpublished` (proposed) | A published entry is withdrawn from public view | `entry_id`, `brand_id`, `unpublished_by_user_id`, `unpublished_at` |
| `ContentEntryArchived` (proposed) | An entry is retired from listings but kept | `entry_id`, `brand_id`, `archived_at` |
| `AnnouncementPublished` | An entry of an announcement-style type is published — the signal Messaging acts on (its listener landed with ADR-0041 point 8) | `entry_id`, `brand_id`, `event_id` (nullable), `slug` (the entry's public address, `/news/{slug}`), `title`, `urgency`, `audience` (scalar selector), `published_at` |
| `CommentPosted` (proposed) | A comment is submitted, whether or not it is yet visible | `comment_id`, `entry_id`, `brand_id`, `author_user_id`, `requires_moderation`, `posted_at` |
| `CommentApproved` (proposed) | A moderator releases a comment | `comment_id`, `entry_id`, `moderated_by_user_id`, `approved_at` |
| `CommentRejected` (proposed) | A moderator refuses or hides a comment | `comment_id`, `entry_id`, `moderated_by_user_id`, `reason_code`, `rejected_at` |
| `CommentReported` (proposed) | A reader reports a comment | `comment_id`, `entry_id`, `reported_by_user_id`, `reason_code`, `reported_at` |
| `CommentReacted` | A reader likes a comment for the first time (a repeated click is silent) | `comment_id`, `entry_id`, `brand_id`, `user_id`, `occurred_at` |
| `CommentReactionRemoved` | A reader withdraws their like (a repeated unlike is silent) | `comment_id`, `entry_id`, `brand_id`, `user_id`, `occurred_at` |

## Consumes

Events from other modules that this module listens to, and the read models it
maintains from them.

| Event | Owning module | Why |
|---|---|---|
| `UserRegistered`, `UserProfileUpdated` | Identity | Maintain the author and commenter read model behind bylines (`Models\Author`, projected by `Listeners\ProjectAuthor`). Display name only — a byline needs a name, not an address, and a read model holding contact data it never shows is a liability with no upside |
| `UserAnonymizationRequested` (proposed) | Compliance | Hand the request to this module's `src/Gdpr/` handler: authored entries and comments need an explicit anonymize-or-remove answer |
| `EventPublished`, `EventUpdated`, `EventArchived` (proposed) | Event | Maintain the event read model for event-scoped entries and stop surfacing content for archived events |
| `BrandCreated` (proposed) | Platform | Seed the brand's default content types and taxonomy |
| `RegistrationConfirmed` (proposed) | Registration | Only if participant-restricted content is built — see open questions; not part of the initial contract |

## Capabilities

Declared in `src/Enums/Permission.php`. Roles that grant them are per-brand
data, not code (ADR-0013).

The catalog covers: creating and editing entries, publishing and unpublishing
(deliberately separate from creating — an author is not automatically an
editor), viewing unpublished and scheduled entries, managing content types and
taxonomy, posting comments, moderating comments, and reacting to comments (no
capability: any signed-in reader who is not the author). Content never
references another module's `Permission` enum.

## Activation

**Core tier: always active.** Every brand needs Content and no Pennant feature
gates it: a public brand identity without news or announcements is not a public
identity. The module self-registers routes, policies, listeners, navigation,
block types, and its GDPR handler through `ContentServiceProvider` at boot
(ADR-0003).

Whether a brand allows comments is a per-brand **setting** inside this module,
not a module activation gate — the distinction matters, because a setting has
no bearing on which listeners and routes exist.

## Open questions

1. ~~**Media ownership.** Whether Platform grows a shared media library that
   Content, Sponsoring, and Admission badges all draw on, or each module keeps
   its own attachments. Decide before a second module needs image uploads.~~
   **Settled by ADR-0035 (decision log Round 6, D-CNT-05):** a Platform-owned,
   brand-scoped media library. Content references media by ULID instead of
   keeping its own attachments.
2. ~~**Localization.** Whether entries carry translations (DACH brands may
   want German and English) and, if so, whether that is a Content concern or
   a platform-wide translation layer.~~ **Decision log Round 6 (D-CNT-06):**
   single locale per brand at release. A translation layer is a post-release
   platform decision.
3. ~~**Audience restriction.** Whether an entry can be visible only to
   confirmed participants. That would make Content depend on registration
   state, which the current boundary avoids — decide deliberately rather than
   by accident.~~ **Decision log Round 6 (D-CNT-07):** entries are public or
   login-required at release; participant-gating is deferred, deliberately —
   it would couple Content to Registration state.
4. ~~**Announcement urgency and delivery.** How much Content declares about
   how an announcement should reach people versus how much Messaging decides
   from its own rules.~~ **Decision log Round 7 (D-CNT-08):** Content declares
   intent only — urgency (`normal`/`urgent`) and audience
   (`everyone`/`logged-in`) on `AnnouncementPublished`; Messaging alone maps
   intent to channels and timing (urgent bypasses digests and quiet hours).
5. ~~**Revisions.** Whether published entries keep an edit history, and
   whether editors need diff and rollback.~~ **Decision log Round 7
   (D-CNT-09):** no revision history at release; the audit trail records who
   published what when.
6. ~~**Comment identity.** Whether comments require an account or allow
   pseudonymous posting, which changes both the moderation and the GDPR
   story.~~ **Decision log Round 7 (D-BATCH-7):** comments require an
   account; no pseudonymous posting.
7. **Block type surface.** Which block types Content contributes to Platform's
   page CMS at release, and how a block declares its data contract to the page
   editor.
