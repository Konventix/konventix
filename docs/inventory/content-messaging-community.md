# Content / Messaging / Newsletter / Community — LanCore inventory

What LanCore actually built across News, Announcement, Publishing, Notification,
EmailLog, Newsletter, Chat, Presence and Achievements, and where each piece lands
in Konventix. Verdicts are proposals with costs attached, not decisions — the
genuinely open ones are collected at the bottom.

Two scope facts shape every verdict below:

- **Content and Messaging are release scope** (Phase 2). **Newsletter and
  Community are Phase 3.** Their inventory is honest but deliberately shallow.
- Because Community is Phase 3 while Competition ships in Phase 2, **Konventix
  has no competition chat at release** even though LanCore does. That is a real
  regression against the predecessor, not an oversight — see the deferred list.

## Sources

LanCore (read-only reference, `/home/mkohn/git/lan-software/LanCore`):

- `docs/mil-std-498/SRS.md` §3.2.7 (NWS), §3.2.8 (ANN), §3.2.9 (ACH), §3.2.10
  (NTF), §3.2.BB (NLT), §3.2.CC (CTD), §3.2.EE (CHT), §3.2.DD (PRS), §3.2.FF
  (PUB); plus EVT-F-013, ORG-F-006, VEN-F-001, SET-F-014, USR-F-023, ORC-F-012
  for requirements that sit outside these families but are realised by them.
- `app/Domain/{News,Announcement,Publishing,Newsletter,Notification,EmailLog,Chat,Presence,Achievements}/`
- `database/migrations/` — 30 migrations covering these tables.
- `routes/{news,announcements,chat,newsletter,notifications,channels}.php`
- `app/Console/Commands/Presence/SweepPresenceTransitionsCommand.php`,
  `app/Domain/Competition/Chat/{CompetitionMemberAnnotator,CompetitionChatLinkResolver}.php`
- `resources/js/components/chat/`, `NotificationBell.vue`, `PresenceIndicator.vue`,
  `announcements/AnnouncementBanner.vue`

Note on requirement counts: the SRS is behind the code. `CHT-F-033..036` and
`PRS-F-013..015` exist only as docblock citations (`ReopenRoom`,
`CompetitionMemberAnnotator`, `CompetitionChatLinkResolver`, presence-channel
join, logout clear, presence broadcast, `presence:sweep`); `CHT-F-006` and
`CHT-F-025` are cited in code but defined nowhere; `NOT-F-020` (broadcast
notification bell) is cited by `NotificationReceived` and absent from the SRS.
Actual families: CHT 35 cited / 30 specified, PRS 15 cited / 12 specified,
PUB 8, NLT 7, NTF 13, ACH 8, NWS 8, ANN 5.

Konventix: `CLAUDE_extra.md`, `docs/architecture/overview.md`,
`docs/architecture/module-map.md`, `docs/lessons-from-lancore.md`,
`docs/decisions/0003,0004,0009,0010,0013,0014`, `docs/ROADMAP.md`, and the
existing `SPEC.md` of content, messaging, newsletter and community.

## Feature inventory

| LanCore feature | SRS ID | What it does today | Konventix module | Proposed name | Verdict |
|---|---|---|---|---|---|
| News article CRUD (title, slug, summary, longtext content, tags JSON, image, author FK) | NWS-F-001 | Backstage editorial CRUD, public `/news/{slug}` | Content | `ContentEntry` (type `news`) | Generalize |
| Three overlapping lifecycle signals: `visibility` enum (draft/internal/public), `is_archived` boolean, `published_at` | NWS-F-001, NWS-F-002 | One enum conflates workflow state with audience, a second flag overlaps it, and publishing just stamps "now" — so scheduling does not exist | Content | publication state machine + publish window | Generalize |
| SEO and OpenGraph metadata (five flat columns) plus a free-form JSON tag array | NWS-F-001 | Indexing metadata and an untyped tag list on the article row | Content | `SeoMetadata` cast + taxonomy | Generalize |
| Comments: content, `is_approved`, `edited_at`, and per-article enable/approval toggles | NWS-F-003 | Account-bound comments, optionally pre-moderated | Content | `Comment` (polymorphic subject) | Port renamed |
| Comment up/down votes | NWS-F-004 | `news_comment_votes` pivot | Content | — | Defer post-release |
| Backstage comment moderation queue (index, approve, edit, delete) | NWS-F-003, NWS-F-007 | Cross-article moderation surface | Content | moderation queue | Port as-is |
| `notify_users` opt-in flag on the article, driving `SendNewsNotification` | NWS-F-005 | The author decides at publish time whether to mail | Content emits, Messaging delivers | `ContentEntryPublished` + template key | Split across modules |
| `NewsArticlePublished` webhook fan-out to satellite apps | NWS-F-005 | Outbound HTTP to registered integrations | Extensibility | webhook subscription | Defer post-release |
| View-tracking events `NewsArticleRead` and `AnnouncementsViewed` | NWS-F-006, ANN-F-004 | Emitted on view; the only consumer is the achievement engine | — | — | Drop |
| Article and comment audit trail with backstage audit pages | NWS-F-008 | owen-it rows and a diff view per model | Compliance | audit stream | Generalize |
| `NewsDataSource` GDPR exporter | GDPR-F-007 | Exports authored articles and comments | Content | `src/Gdpr/` handler | Port as-is |
| Announcement CRUD with an explicit publish endpoint separate from update | ANN-F-001 | Separate model, controllers and policy; `POST /announcements/{id}/publish` | Content | `ContentEntry` (type `announcement`) | Generalize |
| Priority enum `silent` / `normal` / `emergency` | ANN-F-002 | Drives banner styling *and* whether mail is sent | Content (attribute) + Messaging (routing) | `urgency` | Split across modules |
| Audience enum `internal` / `lancore_only` / `satellites` / `all`, plus the public JSON feed `/api/announcements/feed` | ANN-F-001 | A distribution-target list, half of it aimed at other applications rather than people | Content + Extensibility | audience + syndication targets | Split across modules |
| Mandatory `event_id` FK on every announcement | ANN-F-001 | Schema-enforced: an announcement always belongs to one event | Content | nullable event binding | Generalize |
| Site-wide banner with per-user dismissal (`announcement_dismissals`, 60 s per-user cache, shared prop) | ANN-F-003 | One row per user per announcement, rendered on every page | Platform slot + Content or Messaging state | announcement slot | Split across modules |
| `AnnouncementPublished` triggering notification and webhooks | ANN-F-004 | Publishing always notifies opted-in users, no opt-out | Content emits, Messaging delivers | `AnnouncementPublished` | Split across modules |
| LPPS document `GET /.well-known/lan-party.json`, built by `BuildLanPartyDocument`, cached and invalidated on Event/Venue/Address/TicketType writes | PUB-F-001..004 | Machine-readable federation document assembled from the *event catalog*, never from editorial content | Event (not Content) | event syndication feed | Defer post-release |
| LAN-specific event fields: attendance mode, five facility-policy bitsets, three bandwidth columns, showers | EVT-F-013, PUB-F-006 | LAN-party-directory vocabulary carried on `events` | GameOps / event profile extension | — | Drop |
| `syndication_status` gating which events appear, independent of publish status | PUB-F-007 | Per-event opt-out of syndication | Event | syndication opt-in | Generalize |
| Feed inputs owned elsewhere: venue geo (lat/long/country code) and four organisation LPPS settings keys | VEN-F-001, ORG-F-006, PUB-F-005, PUB-F-008 | Geo columns on `addresses`; key/value organisation rows | Event / Platform (brand profile) | venue geo, brand public profile | Port as-is |
| Preference matrix: 8 categories × mail/push = 16 boolean columns | NTF-F-001, NTF-F-002, NTF-F-009, CHT-F-017 | One migration and one settings-page edit per new notification kind | Messaging (storage) + Identity (surface) | topic preferences | Generalize |
| Web push: subscription rows, a custom `webpush` channel pruning 404/410 endpoints, session-scoped permission prompt | NTF-F-003, NTF-F-007, NTF-F-011 | Per-device subscriptions, Minishlink WebPush per send | Messaging | push channel driver | Defer post-release |
| In-app inbox: index, archive view, mark read / mark all read / archive / archive all, with live updates via broadcast `notification.received` | NTF-F-005, NTF-F-006, NOT-F-020 (cited, unspecified) | `notifications` table with `archived_at`; a global listener rebroadcasts to the user's private channel | Messaging | in-app inbox | Port renamed |
| Programme reminder subscriptions | NTF-F-004, PRG-F-005 | Per-user opt-in to one programme item's reminders | Agenda owns subscription, Messaging delivers | agenda reminder subscription | Split across modules |
| Ticket sale open/close reminder scheduler (5-minute cron, `*_notified_at` sentinels, ownership suppression, in-job availability re-check) | NTF-F-008, NTF-F-010, NTF-F-012, NTF-F-013 | The Notification domain scans ticket types and fans out jobs | Registration owns trigger, Messaging delivers | sales window reminder | Split across modules |
| `SeatAssignmentInvalidatedNotification` filed under the Notification domain | NTF-F-006, SET-F-014 | Seating's notification class living in another domain's folder | Resources owns trigger, Messaging delivers | booking invalidated | Split across modules |
| `UserAttributesUpdated` / `UserRolesChanged` events, notifications and webhook listeners | NTF-F-006 | Identity changes announced from the Notification domain | Identity emits, Messaging delivers | — | Split across modules |
| `email_messages`: full HTML and text bodies, headers, to/cc/bcc, `parent_id`, notifiable morph, status enum including bounced and complained | (EmailLog, unspecified) | Every sent mail persisted whole; nothing ever writes the bounced or complained states | Messaging | delivery log + suppression list | Generalize |
| `RecordSentEmail` on `MessageSent`, attribution via `X-LanCore-Source` headers | (EmailLog) | Source classification smuggled through MIME headers, mail only | Messaging | first-class message metadata | Generalize |
| Backstage email overview (index and body view) behind a single `ViewEmailMessages` permission | (EmailLog) | Listing messages and reading their personal data share one capability | Messaging | split `ViewMessageLog` / `ViewMessageContent` | Port renamed |
| `email-log:prune-bodies` retention command | (EmailLog) | Opt-in, default retention 0 — never prunes | Messaging | body retention job | Port as-is |
| `NewsletterList` admin CRUD keyed by `listmonk_id` | NLT-F-001 | Local rows mirroring provider lists, vendor id on the aggregate | Newsletter | `List` + provider list binding | Port renamed |
| `ListmonkClient`, `ListmonkException`, `config/listmonk.php`, and `FetchListsFromListmonk` with its admin sync endpoint | NLT-F-002, NLT-F-007 | Direct HTTP wrapper called from actions; list metadata upserted by vendor id | Newsletter | driver behind `NewsletterProvider` | Generalize |
| User email settings page over curated (`is_user_selectable`) lists, plus admin editing of another user's subscriptions | NLT-F-001, NLT-F-003 | Subscribe/unsubscribe diffs applied per list | Newsletter | subscription preferences | Port as-is |
| Three reconciliation jobs (single user, fan-out, per list) on a daily 03:45 schedule | NLT-F-004 | Keeps local pivot and provider state agreeing in both directions | Newsletter | reconciliation | Port as-is |
| `OptInAllUsersToList` bulk opt-in endpoint | NLT-F-005 | Subscribes every registered user to a marketing list | — | — | Drop |
| Public anonymous subscribe endpoint with a 5/min/IP throttle | NLT-F-006 | Address plus optional name into the default public list | Newsletter | public signup | Port as-is |
| Countdown page: next-event timer plus newsletter signup form | CTD-F-001, CTD-F-002 | A public page combining Event data with a Newsletter form | Platform (block) + Newsletter (form) | countdown block | Split across modules |
| `chat_rooms` keyed by an opaque string, with `consumer_domain` and `policy_class` FQCN columns resolved through `PolicyResolver`, and `RoomPolicy` as the sole cross-domain coupling point | CHT-F-001, CHT-F-007 | Room identity and its authorization strategy stored as strings and instantiated from the container | Community | room type registry + typed subject reference | Generalize |
| `ChatService::ensureRoom` idempotent creation, `RoomStatus` Open/WriteLocked/Archived, and the writeLock / archive / reopen transitions | CHT-F-003, CHT-F-010, CHT-F-011, CHT-F-033 | Subscribe and post eligibility derive from status; repeat creation is a no-op | Community | room lifecycle primitives | Port as-is |
| `PostMessage`: two rate limits (5/10 s, 30/60 s), duplicate window, 2000-character cap, mute check, whitespace squish, six translated exceptions | CHT-F-012, CHT-F-019 | Every post-time rule in one action | Community | `PostMessage` | Port as-is |
| `@username` mention parsing, `mentions_json` persisted at write time, mention-search endpoint | CHT-F-013, CHT-F-014 | Word-boundary anchored so email addresses do not match | Community | mentions | Port as-is |
| `NotifyMentionedUsers` → `ChatMentionNotification` (database always, mail and push by preference), skipping self-mentions and non-viewers | CHT-F-015, CHT-F-016 | Re-checks room visibility per recipient before notifying | Community decides, Messaging delivers | mention notification | Split across modules |
| Moderation — mute, unmute, delete message, close room — audited in `chat_moderation_actions`, with `ManageChat` / `ModerateChat` as fallback authority | CHT-F-002, CHT-F-004, CHT-F-020..024 | The global permission applies when the room policy denies | Community | moderation + capability enum | Port as-is |
| Soft-deleted messages keep the raw body for GDPR while the UI renders a translated placeholder | CHT-F-005, CHT-F-022 | Deleted-but-retained by design | Community | message tombstone | Port as-is |
| Reverb presence channel `chat.room.{id}` and `MessagePosted` as `ShouldBroadcastNow` | CHT-F-008, CHT-F-009, CHT-F-036 | Join returns a user descriptor that drives the member list; archived rooms reject | Community + realtime transport | room channel | Generalize |
| Membership roles `member` / `observer` with `last_read_at` and `muted_until`, plus transient observer join/leave endpoints | CHT-F-001 | Moderators can watch a room without becoming participants | Community | membership | Port as-is |
| Room lifecycle driven by Competition: create and auto-join on RegistrationOpen, auto-join each new team member, write-lock on Finished, cascade-archive on Archived, match rooms on `MatchReadyForOrchestration` and write-lock on `MatchFinalized` | CHT-F-028..032 | Rooms exist only as a side effect of another module's state machine | Competition and GameOps own triggers, Community owns rooms | room lifecycle events | Split across modules |
| Consumer-side enrichment: `CompetitionMemberAnnotator` (team, tag, referee, admin badges) and `CompetitionChatLinkResolver` (room key → embedding page) | CHT-F-034, CHT-F-035 | Chat stays agnostic; consumers add context and deep links by regex on the room key | Competition + Community registry | member annotator, room surface registry | Generalize |
| `chat:prune-archived --older-than=N` (never scheduled, refuses to run without the flag) | CHT-F-026 | Deletes rooms whose backing competition is archived | Community | retention command | Port renamed |
| `ChatDataSource` GDPR export: own messages including deleted, other users' ids obfuscated | CHT-F-018 | Subject-scoped export out of shared rooms | Community | `src/Gdpr/` handler | Port as-is |
| `MatchChatMessage` — in-game chat captured from TMT2 | ORC-F-012 | A second, unrelated chat store owned by Orchestration | GameOps | match chat capture | Port as-is |
| Redis heartbeat `presence:user:{id}` with TTL, `PresenceStatus` Active/Idle/Offline at 300 s / 1800 s, `bulkStatusFor()` in one round trip, no schema at all | PRS-F-001..003, PRS-F-012 | Status derived from heartbeat age; offline users self-clean | Community | presence store | Port as-is |
| `TrackPresence` middleware on the authenticated web group | PRS-F-004 | No heartbeat for guest, API, integration or broadcast routes | Platform or Identity (kernel) | heartbeat middleware | Split across modules |
| Presence as an Inertia shared prop, `PresenceIndicator` component, `useMyPresence()` composable | PRS-F-005..007 | Shared on every request today | Community + Platform | deferred presence prop | Port renamed |
| Presence on Identity surfaces: sortable column on the admin users index, and the public profile where presence is hidden together with the profile to prevent existence leaks | PRS-F-008..011 | Identity pages reading the presence service directly | Identity surfaces, Community data | — | Split across modules |
| `UserPresenceChanged` broadcast to every chat room the user belongs to, `presence:sweep` each minute over all chat members with last-broadcast dedupe, `ClearPresenceOnLogout` | PRS-F-013..015 | Detects passive Active→Idle→Offline transitions that no request triggers | Community | presence broadcast + sweep | Generalize |
| Achievement definitions (name, description, notification text, colour, icon, active flag) with `AchievementPolicy` / `ManageAchievements` and a manual grant action | ACH-F-001, ACH-F-005 | Admin CRUD; no conditions beyond a trigger | Community | achievement | Port as-is |
| `achievement_events` rows binding an event FQCN to an achievement, the `GrantableEvent` enum (14 cases across six domains), `GrantableEventRegistry` for user resolution, and the `ProcessAchievements` queued wildcard listener | ACH-F-002, ACH-F-003 | Trigger configuration stores PHP class names in database rows | Community | trigger keys from a module-contributed registry | Generalize |
| `achievement_user` pivot with `earned_at`, deduplicated per user | ACH-F-004 | Granting is idempotent per (achievement, user) | Community | award | Port as-is |
| `AchievementEarnedNotification` with special rendering in the bell, index and archive | ACH-F-006, ACH-F-007 | Achievement-shaped payload rendered by the notification UI | Community decides, Messaging delivers | `AchievementAwarded` | Split across modules |
| `earned_user_count` and `earned_percentage` rarity with a backfill command, displayed on the public profile | ACH-F-008, USR-F-023 | Rarity against all registered users, 60 s cached; Identity's page renders Community data | Community data, Identity surface | rarity | Generalize |

63 rows.

## Three publishing mechanisms -> one

The premise deserves one correction before the table: LanCore does not have three
*publishing* mechanisms. It has two editorial ones (News, Announcement) and one
**syndication feed of Event-module data** (Publishing / LPPS) that happens to
share the word. `BuildLanPartyDocument` reads `OrganizationSetting`, `Event`,
`Venue`, `Address` and `TicketType`; it never touches an article or an
announcement. Folding it into Content would import Event's data model into the
editorial module for no gain. It belongs to Event as a syndication feed, or
nowhere.

| LanCore mechanism | Distinct capability | Survives in unified model? |
|---|---|---|
| News | Rich body, slug, teaser, hero image, tags | Yes — the base `ContentEntry` shape |
| News | SEO + OpenGraph metadata for public indexing | Yes — typed `SeoMetadata`, available to any type that is publicly addressable |
| News | Comments, per-entry enable/approval toggles | Yes — per-type default, per-entry override |
| News | Comment up/down votes | No — dropped; no abuse or ranking story ever existed for it |
| News | Notification is **opt-in per article** (`notify_users`) | Partially — becomes a per-type delivery policy plus a per-entry override; the author-decides-at-publish UX must be rebuilt deliberately |
| News | Read tracking (`NewsArticleRead`) | No — its only consumer was the achievement engine |
| News | Brand-wide scope (no event binding at all) | Yes — event binding becomes nullable |
| Announcement | Priority `silent` / `normal` / `emergency` | Renamed to `urgency`, but split: the *styling* meaning stays in Content, the *delivery* meaning moves to Messaging. `Silent` currently means "do not notify" — a Messaging decision stored as a content attribute |
| Announcement | Audience `internal` / `lancore_only` / `satellites` / `all` | Only half. `internal`/`all` become an audience selector; `lancore_only`/`satellites` were *machine* distribution targets and have no home until Extensibility ships |
| Announcement | Mandatory event association (non-nullable FK) | Weakened — the merged entity cannot enforce "always has an event" in schema; it becomes a per-content-type validation rule |
| Announcement | Per-user dismissal, one row per user per announcement | Undecided (D-CNT-04). It is per-user *interaction state* on an editorial entity — the only such state in Content |
| Announcement | Site-wide banner surface, cached per user for 60 s, on every page | Yes, but as a Platform UI slot fed by Content, not as a shared prop computed on every request (lesson #5) |
| Announcement | Publish always notifies, no opt-out | Yes, as the announcement type's delivery policy |
| Announcement | Explicit publish action separate from update | Yes — this is the better model and should apply to all types |
| Announcement | JSON feed for satellite applications | No — Extensibility, post-release |
| Publishing (LPPS) | `.well-known` discovery document for a federated LAN-party directory | No — not editorial content. Event module, post-release |
| Publishing (LPPS) | Structured serialisation of events, venues, ticket types, organisation | No — Content has none of these inputs |
| Publishing (LPPS) | `syndication_status` per event, independent of publish status | Yes, but in Event: "published" and "syndicated" are genuinely different decisions and that separation is worth keeping |
| Publishing (LPPS) | LAN-specific event attributes (attendance mode, sleeping/alcohol/smoking/age/food policy bitsets, three bandwidth fields) | No — LAN-party-directory vocabulary; belongs to a GameOps event profile if it returns |
| All three | Draft → published → archived lifecycle, duplicated three times | Yes — this is the whole point of the merge |
| All three | Audit trail on every editorial write | Yes, via Compliance |

**What the merge loses, stated plainly:** the LPPS federation document and its
LAN-specific vocabulary (no editorial home, deferred to Event); the satellite
distribution tier (`lancore_only` / `satellites`) which has no consumer until
Extensibility ships; comment voting; read analytics; and the schema-enforced
guarantee that an announcement always belongs to an event. Two further things
survive only if someone decides how: per-user dismissal state, and the
priority-means-delivery conflation. Everything else consolidates cleanly, and
the win is real — three controller sets, three policies, three publish paths and
three audit wirings collapse into one lifecycle with a type discriminator.

## Event-scoped -> cross-event: what breaks

### Chat

1. **Nothing creates or ends a cross-event room.** Every LanCore room is a side
   effect of a competition's status: created on `RegistrationOpen`, write-locked
   on `Finished`, archived on `Archived`. A brand-wide room has no parent whose
   lifecycle it can borrow. Creation becomes an administrative act and retention
   becomes a published policy rather than a cascade.
2. **Eligibility loses its source.** `CompetitionRoomPolicy::canView` answers
   "confirmed team member". A brand room needs "is a participant of this brand",
   which lives in Registration — and Community may not read Registration models
   (ADR-0004). Community needs a `CommunityMember` read model projected from
   registration events, and an answer for brands that enable Community late
   (the SPEC's own backfill question).
3. **Auto-join stops being harmless.** Auto-joining every team member is tens of
   people. Auto-joining every registrant of a brand across all its events is
   thousands. Three things were sized for small rooms and are not: the rate
   limits (5 messages / 10 s, 30 / 60 s), the mention fan-out (one queued
   notification per mentioned user, with a `canView` check each), and the
   presence broadcast, which sends every status flip to every room the user is
   in.
4. **`presence:sweep` becomes an O(all participants) job.** It currently
   iterates distinct users with at least one chat membership — bounded by active
   competitions. With brand-wide membership that is every participant the brand
   ever had, every minute.
5. **Retention has no anchor.** `chat:prune-archived` deletes rooms whose
   backing competition is archived. Cross-event chat is indefinite by
   construction, so message retention becomes a Compliance rule that must exist
   before the first message is stored, not after.
6. **Moderation authority changes shape.** Today moderation is `ManageCompetitions`
   *or* `ModerateChat` — a competition organiser moderates their own room. A
   brand room has no organiser, so brand-level moderators become a standing role
   with real workload, which is a staffing question, not only a permissions one.
7. **The room key stops encoding the subject.** `competition:{id}:match:{m}` is
   parsed by `CompetitionChatLinkResolver` and `CompetitionMemberAnnotator` with
   regexes. Brand rooms have no subject to encode, and a mixed set of keyed and
   unkeyed rooms makes string parsing worse. This forces the typed
   `(room_type, subject_id?)` model, which is an improvement forced by the change.
8. **Brand isolation is a new requirement.** LanCore had one instance, one
   community. A person in two brands must not see brand A's room list, members,
   or presence from brand B. `BelongsToBrand` covers the rows; it does not cover
   the Redis presence keys or the broadcast channel names, both of which are
   currently keyed by user id alone.

### Presence

9. **The data model survives untouched; the fan-out does not.** `presence:user:{id}`
   is already global and not event-scoped. What is event-scoped is *who hears
   about it*: `UserPresenceChanged` broadcasts to the user's chat rooms. Widen
   the rooms and the same event now hits a channel with thousands of subscribers
   on every idle transition.
10. **Presence keys are per user, not per brand.** Status must be computed once
    but *disclosed* per brand — a user active in brand A must not read as online
    to brand B unless they are a member there. That is a filter LanCore never
    needed and it sits on the hottest path.
11. **Two Identity surfaces depend on a gateable module.** The admin users index
    (PRS-F-008/009) and the public profile (PRS-F-010/011) both render presence.
    Community is Pennant-gated and post-release, so both must degrade to no
    column at all, and the existence-leak rule (PRS-F-011) must be re-proved in
    the new module boundary rather than inherited.
12. **The heartbeat middleware is a kernel concern inside a gated module.** It
    runs on every authenticated web request. Either it lives in a kernel module
    and Community only consumes it, or a gated module inserts middleware into
    the global web group — which the activation model forbids.

### Achievements

13. **The catalog is already global; the triggers are not.** `achievements` and
    `achievement_user` carry no event id, so cross-event awards work today by
    accident. The triggers (`TicketPurchased`, `CartItemAdded`,
    `IntegrationAccessed`) are event-scoped facts from modules that may be
    inactive for a brand — an achievement bound to a GameOps event in a brand
    without GameOps is dead configuration with no error.
14. **Rarity becomes meaningless without brand scoping.** `earned_percentage` is
    `earned_user_count / total registered users`. Across brands that denominator
    is the whole instance, so a brand-specific achievement reads as vanishingly
    rare. Both numerator and denominator have to become brand-scoped.
15. **FQCN strings in `achievement_events.event_class` break on any rename.**
    Moving `App\Domain\Shop\Events\TicketPurchased` to
    `Konventix\Commerce\Events\OrderPaid` silently disables every achievement
    bound to it. Cross-module trigger references must be stable declared keys
    from a registry each module contributes to.
16. **The interesting cross-event achievements need state LanCore never kept.**
    "Attended three of this brand's events" is not a single event — it is a
    counter. Community needs durable per-participant counters projected from
    events, which is a different mechanism from the stateless trigger table.
17. **Late enablement produces an empty history.** Community is Phase 3.
    Everything that would have granted an award during Phase 2 has already
    happened and was never recorded. This is the same question as chat backfill
    and read-model rebuild, and it is really one question about whether the
    platform keeps a replayable event journal (D-CMM-11).

## Renames

| LanCore term | Konventix term | Why the rename forces a better model |
|---|---|---|
| `NewsArticle`, `Announcement` | `ContentEntry` + content type | Two models with the same lifecycle became two of everything: controllers, policies, publish paths, audit wiring, GDPR sources. One entity makes a third type configuration rather than a fourth copy |
| `ArticleVisibility` (`draft`/`internal`/`public`) | publication state + audience | One enum answered two questions, so "internal and scheduled" was unrepresentable. Splitting them makes scheduling possible without touching audience |
| `is_archived` (boolean, alongside visibility) | `archived` state | Two overlapping lifecycle flags allowed contradictory rows (`public` + archived). One state machine cannot |
| Announcement `priority` | `urgency` (content) + delivery policy (Messaging) | `Silent` was never a priority — it was an instruction not to send mail, stored on the content row. The rename makes the leak visible and moves the delivery half to the module that owns delivery |
| Announcement `audience` | audience selector + syndication targets | `satellites` and `lancore_only` are *machines*, not people. Calling both "audience" hid that the enum mixed a visibility rule with a distribution target |
| `Notification` (domain) | **Messaging** (module) | "Notification" names an artifact, so anything notification-shaped drifted in: seating notifications, ticket-sale cron, role-change mails, the profile-updated webhook. "Messaging" names a transport, and a transport obviously has no business watching for reasons to send |
| `NotificationPreference` (16 boolean columns) | topic preferences | Every new notification kind cost a migration and a settings-page edit. Topics keyed by string, declared by the emitting module, make that additive |
| `EmailMessage` | delivery log + suppression list | The name says "an email we sent". The thing needed is per-attempt delivery state across channels, with bounces and complaints feeding a suppression list — LanCore's enum has `bounced` and `complained` cases that nothing ever writes |
| `X-LanCore-Source` mail header | message metadata | Attribution smuggled through MIME headers only works for mail and only if the sender remembers. First-class metadata works for every channel |
| `chat_rooms.consumer_domain` + `policy_class` (FQCN) | room type (registered) | A PHP class name in a data column is a rename landmine and cannot be validated. A registered room type can be checked at boot and gated per brand |
| Room key `competition:{id}:match:{m}` | `(room_type, subject_id)` | Two classes parse this key with regexes today. Typed columns make the link resolver and member annotator lookups instead of string surgery |
| `achievement_events.event_class` (FQCN) | trigger key from a registry | Same landmine, worse blast radius: catalog rows reference six modules' class names |
| Presence "user is online" | community presence signal | Renaming keeps it away from Admission. An online dot must never be read as "this person is at the venue" — Community's own SPEC calls this out, and the rename is what enforces it |
| `NewsComment` | `Comment` (polymorphic subject) | Comments bound to one content type by name cannot be reused for a second type without a second table |
| `NewsletterList` (with `listmonk_id`) | `List` + provider list binding | The vendor id in the aggregate's own table is how Listmonk leaks into everything. Binding it to a driver-owned mapping keeps the contract the load-bearing part |
| Publishing / LPPS | event syndication feed | It publishes the event catalog, not content. Naming it correctly moves it to the module that owns the data and stops it distorting Content |

## Dropped / deferred, and why

**Dropped outright**

- **`OptInAllUsersToList` (NLT-F-005)** — bulk-subscribing every registered user
  to a marketing list without individual opt-in. Compliance owns consent; this
  endpoint exists to bypass it.
- **`NewsArticleRead` and `AnnouncementsViewed`** — emitted only so the
  achievement engine had something to hang on. No analytics consumer, no
  storage, no reporting.
- **Comment votes (NWS-F-004)** — no ranking, no abuse handling, no display
  weight. Add later with a purpose or not at all.
- **LPPS cache group and `<link rel="alternate">` head tag (PUB-F-004, PUB-F-008)**
  — infrastructure for a feature that is itself deferred.
- **LAN-specific event fields (EVT-F-013, PUB-F-006)** — attendance mode,
  sleeping/alcohol/smoking/age/food policy bitsets, three bandwidth columns.
  Vocabulary of a LAN-party directory. If it returns, it returns as a GameOps
  event profile, not as columns on every brand's events.
- **`X-LanCore-Source` header attribution** — replaced, not ported.

**Deferred to a later phase**

- **The entire Community module** (Chat 35 requirements, Presence 15,
  Achievements 8) and **Newsletter** (7) — Phase 3 per the roadmap. The
  consequence worth stating: **Competition ships in Phase 2 without chat**,
  where LanCore has competition-wide and per-match rooms with auto-join,
  moderation and mention notifications. Competition's SPEC should say so
  explicitly rather than let it be discovered.
- **Web push (NTF-F-003, NTF-F-011)** — Messaging's own SPEC sequences mail,
  then in-app, then push. Push subscriptions are per-device rows and a VAPID key
  story; nothing depends on them at release.
- **Announcement satellite feed and all publish-triggered webhooks
  (ANN-F-004, NWS-F-005)** — Extensibility is post-release, and ADR guidance is
  not to publish external contracts before internal ones are stable.
- **LPPS document (PUB-F-001..003, PUB-F-007)** — to Event, post-release. It is
  a genuine differentiator for LAN brands and should not be forgotten, but it
  cannot be built before Event's model settles.
- **Programme reminder subscriptions (NTF-F-004)** — Agenda is Phase 3. The
  *delivery* half is Messaging and ships; the subscription half waits.
- **Realtime notification-bell updates (NOT-F-020)** — conditional on the
  transport decision (D-CMM-08). Without it the inbox is poll-or-reload.

## Open decisions

### D-CNT-01: Single polymorphic entity, or one table per content type?

**Context:** Content collapses News and Announcement into one publishing model
with a type discriminator. News carries slug, teaser, hero image, tags and five
SEO columns; announcements carry urgency, audience, an event binding and per-user
dismissal. A brand-defined third type will carry something else again. ADR-0009
and lesson #11 forbid free-form JSON blobs, which rules out the easy answer.

**Option A — one wide table, nullable type-specific columns:** simplest queries,
one model, one policy, one audit stream. Cons: every new type widens the table
for all types; nullable columns carry no validity guarantee (an announcement with
`og_image` set is representable); the model grows a `match($type)` in every
accessor — the shape LanCore's `User` took.

**Option B — core entry table plus a 1:1 side table per type:** `content_entries`
holds identity, lifecycle, authorship, event binding, taxonomy; `content_news`
and `content_announcements` hold what only they need. Cons: a join per read; a
new type is a migration, not a config row (which contradicts "adding a type is
data"); polymorphic eager loading is fiddly.

**Option C — separate tables per type sharing a `Publishable` concern:** strongest
typing, no discriminator. Cons: reintroduces exactly what the merge exists to
remove — a cross-type listing (the thing every brand front page wants) becomes a
union query, and every new type is a full model, controller and policy again.

**Recommendation:** B. The "adding a type is data" goal is real but overstated —
in practice a new type needs a form, a template and a policy anyway, so it is
never purely data. B keeps one lifecycle, one publish path, one audit stream and
one listing query while letting announcement-only columns be `NOT NULL` where
they should be. Accept the join.

### D-CNT-02: Do comments live in Content or in Community?

**Context:** Content's SPEC claims comments and their moderation. Community's
SPEC claims chat and its moderation — mutes, removals, bans. Both need a
moderation queue, a reason taxonomy, a hidden state and a per-user restriction.
Content is Phase 2; Community is Phase 3.

**Option A — Content owns comments (current SPEC):** ships at release; comments
stay next to the thing commented on; no dependency on a post-release module.
Cons: when Community lands, a brand has two moderation surfaces, two mute
concepts and two reason taxonomies, and moderators must learn both. That is
precisely the duplication the News/Announcement merge was meant to end.

**Option B — Community owns a generic discussion attachable to any subject:**
one moderation model for comments and chat; a moderator's mute means one thing.
Cons: Content cannot ship comments at release, or ships them as a stub that
Community later has to absorb — a migration of user-generated content, which is
the worst kind.

**Option C — Content owns comment *data*, a shared moderation contract lives in
Compliance or Platform:** both modules record moderation actions in one shape and
enforce restrictions from one place.

**Recommendation:** A for storage and display, with C's discipline applied now:
define the moderation record shape (actor, subject reference, action, reason
code, expiry) and the participant-restriction concept in a shared place before
writing Content's moderation queue. Cost: a small amount of upfront design for a
module that does not exist yet. Benefit: Community adopts it instead of
inventing a second one.

### D-CNT-03: Are announcements content, or are they messages?

**Context:** An announcement is authored like content but exists to reach people
now. LanCore modelled the delivery decision *on the content row*: `priority =
silent` means "do not notify", `audience = satellites` means "push to other
apps". Content's SPEC open question 4 asks exactly this and leaves it open.

**Option A — a content type; Messaging listens to `AnnouncementPublished`:**
authoring, versioning, archiving and audit come free; the banner is a Content
read. Cons: Content must express *something* about delivery (urgency at minimum),
and every new expressive knob is a new leak into Messaging's job.

**Option B — a Messaging broadcast with no editorial entity:** delivery-shaped
from the start, reuses templates, preferences and the delivery log. Cons: loses
the archive page, the permanent public record, and the editorial lifecycle. An
announcement that only exists as a sent message cannot be corrected or archived.

**Option C — both: a Content entry plus an explicit Messaging campaign object:**
clean separation, publish and send become distinct acts. Cons: two objects for
one user action; someone will publish without sending and file a bug.

**Recommendation:** A with a hard boundary. Content declares only `urgency` and
`audience` as *content attributes*. The mapping from urgency to channels, and
whether a given urgency sends at all, is Messaging configuration per brand and
per template key. LanCore's `Silent` becomes "no template bound to this
urgency" — a Messaging fact, not a content one. Write this into both SPECs, or
it will be re-litigated in the first pull request.

### D-CNT-04: Where does "I have seen this announcement" live?

**Context:** LanCore keeps `announcement_dismissals`: one row per user per
announcement, written when a user closes the banner. The banner reads it through
a 60-second per-user cache on every page. Messaging separately keeps in-app
notification read state for the same user, about the same announcement.

**Option A — Content keeps a dismissal table:** self-contained; dismissal is
distinct from "read" (you can read the mail and still want the banner); no
dependency on Messaging's inbox. Cons: an unbounded N×M write table on a
user-interaction path inside an editorial module, and a second concept of "seen"
for the same object.

**Option B — reuse Messaging's in-app read state:** one concept of seen, one
table, already needed. The banner renders from the inbox. Cons: the banner slot
now depends on Messaging (a kernel module, so always available — acceptable) and
"dismissed the banner" and "read the notification" become the same act, which is
a product decision, not a technical one.

**Option C — client-side only (local storage):** zero writes. Cons: dismissal
does not follow the user across devices, and an emergency announcement
re-appears — which for `emergency` is arguably correct and for `normal` is
annoying.

**Recommendation:** B for `normal` urgency, C for `silent`, and never dismissible
for `emergency`. Content stores no per-user state at all, which keeps the
editorial module free of user-interaction write load.

### D-MSG-05: Preference matrix — boolean columns, template keys, or topics?

**Context:** LanCore has 16 boolean columns on `notification_preferences`
(`mail_on_*` / `push_on_*` across news, events, comments, programs,
announcements, seating, ticket sale, chat mention). Every new notification kind
cost a migration, a settings-page edit and a preference read in the notification
class. Security-relevant notifications (TKT-F-025) are explicitly not
suppressible — an exception encoded in code, not in the model.

**Option A — keep boolean columns:** exhaustive, typed, trivially queryable,
visible in the schema. Cons: a migration per feature, and cross-module coupling
by column name — Community adding chat mentions means altering a Messaging table.

**Option B — one row per (recipient, template key, channel):** maximum
precision. Cons: users do not think in template keys; the settings page becomes
a list of dozens of technical toggles; defaults for a key that has never been
sent do not exist.

**Option C — topics: modules declare a topic (key, label, default channels,
suppressible flag) at boot; preferences are rows per (recipient, topic,
channel):** the settings page is generated from the registry, a new topic is
additive, and non-suppressible is a property of the topic rather than a special
case in code.

**Recommendation:** C. Cost paid honestly: no compile-time exhaustiveness, a
registry that must be populated before preferences can be rendered, and topics
belonging to inactive modules must be filtered per brand. All three are cheaper
than a migration per notification kind, which is what LanCore actually paid.

### D-MSG-06: Who owns the preference of record — Identity or Messaging?

**Context:** The two written SPECs disagree in effect. Messaging's says "Identity
owns the stated preference; Messaging keeps a read model of it and applies it".
But under D-MSG-05's topic model, topics are *declared by the modules that emit
them*. If Identity stores preferences, Identity must know every module's topic
catalog — reproducing exactly the god-object shape lesson #2 records.

**Option A — Identity owns storage (as written):** preferences sit with the
account, next to locale and profile; one settings namespace. Cons: Identity gains
a registry of every module's topics, or stores opaque keys it cannot validate or
render; and Messaging enforces a rule from a read model it cannot correct.

**Option B — Messaging owns storage; Identity links to the page:** the module
that applies the rule also stores it, so there is no read-model lag between
saving a preference and honouring it; the topic registry lives next to the
templates that use it. Cons: contradicts a line in the Messaging SPEC; "notification
settings" appears under settings but is served by another module (a routing
detail, not an architecture problem).

**Recommendation:** B, and amend both SPECs in the same pull request. The
deciding argument is failure mode: with A, a user who unsubscribes and
immediately receives the mail anyway is a legitimate bug report about a
consistency window on a legally sensitive path. With B there is no window.

### D-MSG-07: Does the delivery log store rendered bodies?

**Context:** LanCore stores full HTML and text bodies of every mail in
`email_messages`, indefinitely — `email-log:prune-bodies` exists but defaults to
`body_retention_days = 0`, which disables it. So the default posture is
"personal data in mail bodies, kept forever, readable by anyone with
`ViewEmailMessages`".

**Option A — keep bodies, mandatory retention window:** support can see exactly
what was sent; no re-rendering ambiguity. Cons: the largest concentration of
personal data in the system sits in a log table; GDPR export and deletion must
walk it; retention becomes a Compliance obligation Messaging must implement.

**Option B — store the typed payload plus template key and version, re-render on
demand:** far less personal data at rest, and the payload is already typed
(ADR-0009). Cons: re-rendering after a template change is not what the recipient
saw — which is exactly the case where you needed the record.

**Option C — hybrid: payload always; bodies only for template keys flagged
legally significant (invoices, policy versions, deletion confirmations), with a
per-flag retention window.**

**Recommendation:** C, with the flag defaulting to off. It puts the expensive,
sensitive storage only where a legal argument requires it, and makes that
requirement explicit at the template rather than implicit in a global setting
nobody set.

### D-CMM-08: Keep Reverb, or change realtime transport?

**Context:** LanCore broadcasts five things: chat `MessagePosted`,
`UserPresenceChanged`, the notification bell (`NotificationReceived`), and two
competition-board events. Transport is Reverb with Echo and `pusher-js`. Two of
those five belong to **release-scope** modules (Messaging's bell, Competition's
board), so this decision cannot be deferred with Community. Konventix also makes
it harder than LanCore had it: brands are served on arbitrary domains via
on-demand TLS, and websocket origin, session and channel authorisation all have
to work per brand domain.

**Option A — keep Reverb, one shared websocket host** (e.g. `ws.<operator
domain>`) for all brands, authorising over the app session and using
brand-prefixed channel names. Pros: proven code path, `ShouldBroadcastNow`
semantics already correct, presence channels give the chat member list for free.
Cons: a second long-running process in the deployment; cross-origin websocket
auth from each brand domain; channel names must be brand-scoped by convention,
enforced by a test rather than by the type system; scaling needs Redis pub/sub.

**Option B — Reverb per brand domain:** no cross-origin problem. Cons: a
websocket endpoint per brand domain, multiplying certificate and process
management by the number of brands. Rejected on operations cost.

**Option C — polling for the bell, SSE for chat:** no extra long-running
service; the bell polls a cheap endpoint, chat uses server-sent events over the
existing HTTP stack. Cons: the competition board's live Gantt degrades; presence
loses its point (a sweep that broadcasts nothing is just a cron job); SSE plus
FrankenPHP worker mode needs its own proving.

**Option D — no realtime at release:** the bell updates on navigation, the board
refreshes on interval. Cons: the competition board is a differentiator and it is
a live-ops tool.

**Recommendation:** A, decided in Phase 2 for the bell and the board, with chat
inheriting it in Phase 3. Write it as an ADR — Community's SPEC already flags
chat transport as needing one, and this is that ADR, arriving a phase earlier
than expected.

### D-CMM-09: What does "cross-event" actually mean for chat rooms?

**Context:** Every LanCore room is bound to a competition or a match by key, and
every eligibility rule reads from that binding. Konventix's differentiator is a
community spanning events. "Cross-event" can mean at least three different
products.

**Option A — event rooms as today, plus one persistent brand lobby:** smallest
change; per-event rooms keep their tight eligibility and natural end of life;
the lobby is the only thing needing brand-wide membership. Cons: the lobby is
where the hard problems live (scale, moderation, retention) and gets built
anyway; two mental models for users.

**Option B — rooms are brand-level only; events are a tag or filter:** truly
cross-event; one history for a participant across three events. Cons: a match
room makes no sense as a filtered view of a brand room; per-match ephemeral
rooms are a real feature LanCore has and this loses them.

**Option C — every room has an optional event binding; eligibility defaults to
"brand member" and narrows via the room type:** one model, both shapes. A match
room is a room type with a subject and a narrow policy; a lobby is a room type
with no subject and the default policy. Cons: eligibility becomes a per-room-type
strategy — essentially LanCore's `RoomPolicy` again, which is fine, but it must
be a registered type rather than an FQCN column.

**Recommendation:** C, with the `CommunityMember` read model (projected from
registration confirmation events) as the default eligibility source, and rate
limits that scale with room size rather than being global constants. Deciding
this before Phase 3 matters because it determines whether Registration needs to
emit a membership-shaped event, which is a Phase 2 change.

### D-CMM-10: Who owns the presence heartbeat when Community is gateable?

**Context:** `TrackPresence` runs on every authenticated web request. Presence is
rendered on two Identity surfaces (admin users index, public profile). Community
is Pennant-gated and post-release. A gated module cannot install middleware into
the global web group, and a kernel surface should not break when a module is off.

**Option A — Community owns middleware, Redis keys, derivation and surfaces:**
clean ownership; presence disappears entirely when the feature is off, which is
arguably correct. Cons: Identity's admin list loses a column depending on a
brand's feature flags — a conditional surface in a kernel module; and the
middleware registration problem stands.

**Option B — Platform or Identity owns the heartbeat and status derivation;
Community owns only the community-facing surfaces and the broadcast fan-out:**
the kernel keeps a tiny always-on capability (one middleware, one Redis key, one
enum, no schema — LanCore's is four files); Community adds indicators, member
lists and broadcasts on top. Cons: a kernel module gains a feature not every
brand wants.

**Recommendation:** B. The cost is four files and no schema; the alternative is
either a broken kernel surface or a gated module reaching into the global
middleware stack. Keep the brand-disclosure filter (a user's status is visible
only to brands they belong to) with the derivation, not with the surfaces, so it
cannot be forgotten by a caller.

### D-CMM-11: Are achievements Community-owned, or a cross-cutting service?

**Context:** LanCore binds achievements to event FQCNs from six domains via a
wildcard queued listener. Konventix would have Community — a Phase 3, gateable
module — listening to Phase 2 modules' events. Anything that happens before
Community is enabled is unrecorded and unrecoverable.

**Option A — Community owns catalog, triggers and awards (current SPEC):**
achievements are a community feature and live with the profile that displays
them; only one module holds the catalog. Cons: catalog rows reference other
modules' trigger keys, so gating and renames need a registry; and nothing exists
before Phase 3.

**Option B — a thin awards service in Platform fed by every module; Community
only displays:** always-on, so the history exists from day one and enabling
Community later reveals awards already earned. Cons: puts a domain feature in the
kernel; Platform grows an opinion about participant behaviour; the display and
the record split across two modules.

**Option C — each module owns its own achievements:** perfect locality, no
cross-module trigger references. Cons: no single catalog, no rarity computation,
no unified profile display, and the same UI reimplemented per module. Rejected.

**Option D — A plus a durable module event journal in Platform:** every published
module event is appended (name, version, scalar payload, brand, timestamp).
Late-enabled projections replay it. Cons: real infrastructure — storage growth,
a retention policy of its own, versioned payload deserialisation, and it must be
built in Phase 2 to be useful in Phase 3.

**Recommendation:** D, if the journal earns its keep beyond achievements — and it
does: it is the same mechanism that answers Community's chat backfill question,
Messaging's read-model rebuild ("projections must be idempotent and replayable",
ADR-0004), and Newsletter reconciliation after downtime. If it is built only for
achievements, choose A and accept an empty pre-Phase-3 history. Decide by asking
whether ADR-0004's replayability requirement is currently backed by anything at
all; if the answer is no, that is the real finding here.

### D-NLT-12: What shape must the `NewsletterProvider` contract have?

**Context:** The module map says Newsletter is "a Listmonk client behind a
contract" and the SPEC says the contract matters more than the vendor. LanCore
has no contract: `ListmonkClient` is called directly, `listmonk_id` sits on the
aggregate, `listmonk_subscriber_id` sits on the pivot, and three reconciliation
jobs exist because both sides are masters.

**Option A — thin CRUD mapping (subscribe, unsubscribe, status, listLists):**
easy to implement, matches Listmonk one-to-one. Cons: it *is* Listmonk's shape —
a provider with different semantics (double opt-in owned provider-side,
segments instead of lists) cannot implement it honestly, so the contract buys
nothing.

**Option B — intent-based (`recordOptIn(address, list, provenance)`,
`recordOptOut`, `reconcile`), provider free to model storage differently:**
expresses what Konventix means rather than what Listmonk stores. Cons: still
two masters, so reconciliation and its conflict policy remain.

**Option C — Konventix owns subscription truth; the provider is a sink** —
one-way push with an outbox, plus an inbound path only for provider-side
unsubscribes (which are legally binding and must flow back). Cons: provider-side
list edits by an admin are lost or must be re-pushed; the outbox is real work;
"who wins" is decided once and forever, which some operators will dislike.

**Recommendation:** C. LanCore's three reconciliation jobs and the SPEC's open
"which side wins" question are both symptoms of two-master design. One-way push
plus an unsubscribe inbound path collapses that to a single narrow conflict with
an obvious resolution: an unsubscribe always wins. Settle this before any schema,
as the SPEC already warns — the answer determines whether `listmonk_id` may
appear on a Konventix table at all (under C it may not; it lives in a driver-owned
mapping).
