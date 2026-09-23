# The event shoutbox and the presence model

Date: 2026-09-23. Status: approved design (operator decisions 2026-09-23, recorded in §11).
Release: 0.9.0, sub-project **D** of four. Issue: #108 (Event-Shoutbox und Chat Wall), labelled `risiko::datenschutz`.
Neighbours: A — the live-event hub (`2026-09-22-event-hub-design.md`, shipped), whose card registry and presence events this builds on; B — Competition (#296–#302), which later supplies match and competition rooms; C — the timetable (`2026-09-23-timetable-design.md`).
Decisions this rests on: **ADR-0067** (this design's own: Community owns event-scoped rooms and wears an admission badge it does not enforce), ADR-0003 (module activation), ADR-0004 (events are the only coupling), ADR-0009 (typed Data), ADR-0010 (brand ownership), ADR-0013 (capabilities), ADR-0014 (ULIDs, no cross-module FKs), ADR-0021/ADR-0025 (realtime transport — Reverb, already decided and proven), ADR-0035 (media library), ADR-0050 (GDPR subject spans the operator).

## 1. Goal

A participant who has been let in at the door opens the live hub and finds the room talking: who is here, what is happening, where the queue for the Lötkurs actually is. An organizer can remove a line, silence someone for ten minutes with a reason they can read, or shut the wall entirely when it has gone bad at 03:00 and nobody wants to moderate line by line.

This is #108's "Event-Shoutbox und Chat Wall". The issue's own 02.09 assessment is still accurate: the transport is built and proven — Reverb as a deployment role, Echo plus pusher-js, private-channel authorisation, and one live consumer in the notification bell — while the feature itself is entirely absent. There is no message model, no room, no route, no page, no moderation, no rate limit, no closing and no retention. This design is all of that.

## 2. Facts the design rests on

Verified 2026-09-23 on `develop`.

- **Community is a real module, not a scaffold.** `CommunityMember`, `CommunityProfile`, `CommunityComment`, `CommunityReaction`, `CommunityEventParticipation`, five test files and a GDPR handler already ship. Profiles and comments work. `ProjectEventParticipation` is the model for a gated, idempotent projection in this module.
- **Community's SPEC says two things this design changes**, and both are amended in §10 rather than quietly contradicted: that Community owns the community "**between** events, not inside one", and that presence "**must never be treated as an attendance or access record**".
- **The hub's card registry is live** (sub-project A): a module registers a `HubCard(module, key, label, component, Closure $payload, ?permission, order)` from `registerHub()`; `HubPage` resolves the payload only for cards the operator's layout keeps and contains a throwing card rather than failing the page. Components live at `app-modules/<module>/resources/js/components/hub/<Name>.vue`, resolved by the name `<module>/hub/<Name>`, and `tests/Feature/HubComponentBoundaryTest.php` forbids one importing another module's code.
- **A's presence events exist and were built for this consumer**: `ParticipantAdmitted(userId, eventId, brandId, admissionId, admittedAt, ?windowEndsAt, ?dayLabel, ?eventName, ?eventSlug)`, `ParticipantPresenceChanged(userId, eventId, brandId, direction, changedAt)`, `ParticipantHubAccessEnded(userId, eventId, brandId, reason, endedAt)`, `ParticipantAdmissionReplaced(…)`. Platform's `ActiveEventContext` answers "which running event is this member admitted to" from one cached read.
- **Realtime precedent to copy**: `NotificationReceived` implements `ShouldBroadcastNow` and broadcasts on the recipient's private channel, which `routes/channels.php` authorises; the bell consumes it. `config('broadcasting')` and the brand's `reverb_app_key` are already plumbed through `HandleInertiaRequests`.
- **Compliance already starts a retention clock** on `EventConcluded` (`StartRetentionClock`, an `updateOrCreate` keyed on the event). `EventConcluded` carries `eventId`, `brandId`, `concludedAt`.
- **Rate limiting pattern**: named limiters declared in the owning provider (`RateLimiter::for`) with `throttle:<name>` on the route, plus a second axis in the Action where the first is not enough (`PublicDataRequestController` is the precedent).
- **Patterns**: `Data` objects with `rules()` (ADR-0009), an Action per write (ADR-0015), thin controllers, `BelongsToBrand` everywhere, explicit listener registration (discovery is off), `src/Metrics/<Module>Metrics.php` plus `ObservabilityCoverageTest`, participant copy German and console copy English.

## 3. Architecture

```
ADMISSION   ParticipantAdmitted ─────┐
            ParticipantPresenceChanged ─┐
            ParticipantHubAccessEnded ──┤
EVENT       EventConcluded ─────────────┤
                                        ▼
COMMUNITY   community_event_presence ─ the DURABLE half: who the door has seen
            community_rooms          ─ subject = event now, match/competition later
            community_messages       ─ body, soft deletion with a reason
            community_room_mutes     ─ per person, timed, reason required
            community_message_reports─ the desk's queue
                     │
                     ├── Redis, TTL'd, never persisted:
                     │   online/away  ·  active-in-this-room
                     │
                     ├── emits ShoutboxMessagePosted / …Removed / …Reported
                     │
                     └── broadcasts over Reverb on a private per-room channel
                              │
        ┌─────────────────────┴───────────────────────┐
        ▼                                             ▼
   hub card `community.shoutbox`              /live/shoutbox  (full room)
   (via A's HubRegistry)                      admitted participants only
                                                      ▲
                                              moderation console
                                              (capability, NOT admission)
```

Ownership follows the truth: Admission knows who came through the door and publishes it; Community owns what is said, who may say it, and who is watching. Community reads no other module's tables.

## 4. The model

All tables `BelongsToBrand` (ADR-0010), ULID primary keys, no foreign keys across module boundaries (ADR-0014).

### 4.1 `community_rooms`

```
id ulid, brand_id, subject_type enum(event|match|competition), subject_id ulid,
locked_at timestamptz nullable, locked_by_user_id ulid nullable, locked_reason nullable,
closed_at timestamptz nullable, timestamps
unique (subject_type, subject_id)
```

A room is **first-class from the start** even though 0.9.0 creates only one kind. Per-match and per-competition rooms are coming (they wait on B), and retrofitting a room onto an `event_id` column on every message would be the more expensive change. `subject_id` is a plain ULID: the subject lives in another module.

The event's room is created **lazily, on first read** — no listener, no backfill, and a brand that never opens the wall carries no row.

`closed_at` is set by `EventConcluded` and is what starts the retention clock (§7). A closed room is readable until it is swept; it is not writable.

### 4.2 `community_messages`

```
id ulid, brand_id, room_id (FK, same module), author_user_id ulid,
body varchar(500),
deleted_at timestamptz nullable, deleted_by_user_id ulid nullable, deleted_reason nullable,
timestamps
index (room_id, id)
```

**Deletion is soft.** A removed line leaves a tombstone so that a report naming it still resolves and the desk can see what it acted on. The tombstone renders as "Von der Orga entfernt" and carries neither body nor author to other participants.

`author_user_id` is a plain ULID — Identity's user is never imported. Display names come from the existing `CommunityProfile` read model.

Ordering is by `id`: ULIDs are monotonic, so this is chronological without trusting a clock, and it makes the "since this id" fetch the realtime client needs a primary-key range scan.

### 4.3 `community_room_mutes`

```
id ulid, brand_id, room_id (FK), user_id ulid, muted_by_user_id ulid,
reason string (NOT NULL), expires_at timestamptz nullable, timestamps
index (room_id, user_id, expires_at)
```

Twitch-shaped, and deliberately so: a **timed** mute with a **required reason**. `expires_at` null means "for the rest of the event".

**The reason is shown to the muted person** — "Du kannst 10 Minuten nicht schreiben: Spam". A silent mute reads as a broken app and produces a support request instead of a behaviour change.

Mutes are per **room**. In 0.9.0 that is the same as per event, because there is one room; when match rooms arrive, a mute that should span them becomes a visible decision rather than an accident of the schema.

### 4.4 `community_message_reports`

```
id ulid, brand_id, message_id (FK), reporter_user_id ulid, note nullable,
state enum(open|resolved|dismissed), resolved_by_user_id ulid nullable, resolved_at nullable,
timestamps
unique (message_id, reporter_user_id)
```

Any participant may report a line once. The desk works the open list. This table is also **the seam a later auto-moderation API feeds into** (§9): a classifier writes reports like any other reporter, and the desk's workflow does not change. That is why the answer to moderation is a queue and not a filter list.

### 4.5 `community_event_presence` — the durable half

```
id ulid, brand_id, event_id ulid, user_id ulid,
checked_in bool, last_direction enum(in|out), updated_at
unique (event_id, user_id)
```

Projected from A's participant events. This is the **only** presence fact written to disk, and it is a badge sourced from Admission, not a record of who was at their keyboard.

## 5. Presence: two axes and a room signal

| Axis | Values | Where it lives | Source |
|---|---|---|---|
| Connection | Online · Away · Offline | **Redis, TTL'd, never persisted** | Reverb connection + heartbeat |
| Physical | Checked-In | `community_event_presence` | `ParticipantAdmitted` / `ParticipantPresenceChanged` |
| Room | active in *this* room | **Redis, TTL'd** | Reverb presence channel per room |

They are **independent and compose**. "Checked-In + Offline" is a real and useful state — at the event, phone in a pocket — and renders as a badge with no dot. "Online, not checked in" is an organizer at a desk.

Nothing about *when* a person was online is ever written to disk. That is what keeps §10's amended SPEC sentence honest: the durable half is an admission badge, and the ephemeral half evaporates with its TTL.

A Reverb restart loses the connection and room axes — correctly, since they describe a connection that no longer exists — and loses nothing durable. The badge survives.

## 6. Access, moderation, rate limits

**Reading and writing require admission to the running event** — the same gate as the hub (A's `ActiveEventContext`). Everyone in the room is physically at the event, which is most of the moderation problem solved before it starts.

**Moderation does not require admission.** An organizer at their desk who was never scanned in must still be able to act, so the moderation path is gated on capability alone. This is the one place where the room's audience and its moderators are deliberately different sets.

Three verbs, all post-hoc — there is no pre-publication queue, because nobody shouts into one, and no filter list:

| Verb | Effect |
|---|---|
| Remove a message | soft delete with a reason; the tombstone stays |
| Mute a person | timed, reason required, shown to them |
| Lock the room | existing messages readable, nobody may post; reversible |

**Rate limits**, enforced in the Action and not only in the UI: **5 messages per 10 seconds** per person per room, and the 500-character cap of §4.2. Enough for a LAN's chatter; tight enough that a bored guest cannot flood the wall.

## 7. Retention

`EventConcluded` closes the room (`closed_at`). A scheduled sweep deletes the room's messages, mutes and reports **30 days** after that instant.

A shoutbox is ephemeral by nature, a short clock is the easiest thing to defend, and it keeps the module out of the business of holding years of unmoderated chat. The room row itself survives as a tombstone so a link does not 404 into nothing.

**GDPR**, not optional: a participant's export includes their own messages and their own reports; erasure removes their messages outright (a shoutbox line is not a business record) and anonymises the `reporter_user_id` on reports they filed, which stay as facts about the message. `community_event_presence` rows are exported and deleted with the subject.

## 8. Surfaces

| Surface | Route | Who | What |
|---|---|---|---|
| Hub card | via A's `HubRegistry`, key `community.shoutbox`, component `community/hub/ShoutboxCard` | admitted participants | the last few lines and an inline compose box |
| The room | `/live/shoutbox` | admitted participants | full-height room, presence list, infinite scroll upward |
| Moderation | `admin/events/{event}/shoutbox` | `ModerateCommunity` | the open report queue, mute list, lock switch |

Live over Reverb on a **private per-room channel**, authorised in `routes/channels.php` the way the notification bell's channel already is. The client renders optimistically and reconciles on the broadcast; a failed post rolls back with a toast.

## 9. Public contract (events out)

| Event | Emitted when | Payload |
|---|---|---|
| `ShoutboxMessagePosted` | a message is accepted | `message_id, room_id, brand_id, event_id, author_user_id, posted_at` |
| `ShoutboxMessageRemoved` | an organizer removes one | `message_id, room_id, brand_id, removed_by_user_id, reason, removed_at` |
| `ShoutboxMessageReported` | a participant reports one | `report_id, message_id, room_id, brand_id, reporter_user_id, reported_at` |

No body text travels on any of them — a consumer that needs the text reads it through Community's own surface, and an event payload is not a place to copy user-generated content.

Nothing consumes these in 0.9.0. They exist so that **auto-moderation through an API** can arrive later as a listener on `ShoutboxMessagePosted` that writes a `community_message_reports` row, without Community learning what a classifier is and without the desk's workflow changing.

## 10. Two SPEC amendments this forces — recorded as ADR-0067

**A word of warning before the amendments: "presence" already means something
else here.** ADR-0054 uses it for *an account's presence on a brand* — the
membership that decides what a brand may show or send. That is unrelated to
the online/away/checked-in signal of §5. Where it matters, prefer **connection
state** or **admission badge** over the bare word.

Both amendments below are settled in **ADR-0067**, not left as footnotes in a
feature's spec:

Written with their reasons, not quietly contradicted:

1. **"Community owns the community between events, not inside one"** becomes: Community is **brand-scoped and owns event-scoped rooms within it**. The cross-event community identity (profile, achievements, membership) is unchanged; a room is the one thing that belongs to a subject. The alternative — a second chat module for event-day chat — would duplicate moderation, presence and profiles.
2. **"Presence must never be treated as an attendance or access record"** gains its exception: Community projects a **Checked-In badge** from Admission's public events. It is displayed, never enforced — nothing in Community grants or refuses access on it, and the access gate is A's `ActiveEventContext`, which asks Admission's own answer.

## 11. Decisions recorded (operator, 2026-09-23)

| # | Decision |
|---|---|
| D-COM-01 | **Post-hoc moderation with participant reporting.** No pre-publication queue and **no filter lists** — filter lists are the wrong approach for this use case. |
| D-COM-02 | **Auto-moderation through an API is a later addition**, and the reports table is its input, so nothing is rebuilt when it arrives. |
| D-COM-03 | **Admitted participants only**, for both reading and writing, in 0.9.0. Widening reading to any signed-in member later costs one read gate. |
| D-COM-04 | **Moderation is gated on capability, not admission** — an organizer who was never scanned in must still be able to act. |
| D-COM-05 | **Retention: 30 days after `EventConcluded`**, then deleted. GDPR export and erasure regardless. |
| D-COM-06 | **Presence is two composing axes plus a room signal**: connection (Online/Away/Offline) and room activity are **ephemeral in Redis, never persisted**; Checked-In is **durable**, projected from Admission's events. |
| D-COM-07 | **Rooms are first-class**, with a subject (`event` now; `match`, `competition` later). *(controller's call, approved)* |
| D-COM-08 | **Three moderation verbs**: remove a message, **mute for N minutes with a required reason shown to the muted person** (Twitch-shaped), and lock the room. |
| D-COM-09 | **Rate limit** 5 messages / 10 s per person per room, 500-character cap, enforced server-side. *(controller's proposal, approved)* |

## 12. Testing and definition of done

1. An admitted participant posts; every other admitted client sees it over Reverb without a reload.
2. A participant who is **not** admitted can neither read nor write, and the hub card is absent for them.
3. The rate limit refuses the sixth message in ten seconds with a German message, and the refusal comes from the Action (a client that ignores the UI is still refused).
4. An organizer **without admission** removes a message, mutes its author for 10 minutes with a reason, and locks the room; the muted person sees the reason and the remaining time, and cannot post; after ten minutes they can.
5. A locked room is readable and not writable; unlocking restores posting.
6. A reported message appears in the desk queue exactly once per reporter and can be resolved or dismissed.
7. `EventConcluded` closes the room; the sweep deletes its messages 30 days later and leaves the room tombstone.
8. GDPR: export carries the subject's own messages and reports; erasure removes the messages and anonymises the reports they filed.
9. Presence: Checked-In survives a Reverb restart; online/away and room activity do not and are never written to disk.
10. With `community` off for a brand: no routes, no hub card, no listeners, no errors.

## 13. Out of scope

Auto-moderation (§9 leaves its seam), per-match and per-competition rooms (the subject enum is ready, the subjects are not — they wait on B), achievements, cross-event message history, direct messages, file or image posting, and any widening of who may read (D-COM-03).

## 14. Deviations after review

- **§5's "Checked-In + Offline" row is dropped (final review I1, 2026-09-23, provisional).** Listing every checked-in attendee to every admitted participant is an attendee roster, and [ADR-0064](../../decisions/0064-an-attendee-list-is-opt-in.md) makes an attendee list opt-in per person and per event, keeps the account ULID off it, and names people by username. "Wer ist da" therefore lists only the people active in the room on the connection axis, each still carrying the Checked-In badge, and its rows carry no account ULID (`WallReader::presence()`). Whether absent people who opted in may appear, and by which name, is open for the operator (#324).
