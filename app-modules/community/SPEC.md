# Community — Specification

> Stable document. Describes what this module *is*. Task tracking lives in
> `TODO.md`.

> **Post-release module.** Community is Phase 3 in `docs/ROADMAP.md` —
> deliberately after release scope. Do not start building it earlier. What
> follows is provisional: the ownership boundary is the part worth trusting,
> the rest is marked proposed or left as an open question.

## Purpose

Community owns the participant community that exists **between** events, not
inside one. It absorbs LanCore's Chat, Presence, and Achievements domains and
gives them a home scoped to the brand rather than to a single event — this
cross-event community is one of the platform's stated differentiators against
pure ticketing tools. A person who attended three of a brand's events has one
community identity, one message history, and one set of achievements across all
of them.

## Owns

- **Community profile** — a per-brand read model of a participant (display name,
  avatar, member since), projected from Identity events. Identity's user stays
  slim (ADR-0004); the community-facing profile lives here.
- **Chat** — channels, membership, messages, and their moderation state.
- **Presence** — the online/idle signal used in community surfaces. Ephemeral by
  nature.
- **Achievements** — the per-brand catalog of achievements and the awards handed
  to participants. The facts that trigger an award come from other modules'
  events; the award record is Community's.
- **Community moderation** — mutes, bans, and message removals within the
  surfaces above.

Everything here is brand-owned and uses `BelongsToBrand` (ADR-0010). Being
brand-scoped rather than event-scoped is the point of the module, not an
oversight.

## Does not own

- **User accounts, authentication, profile of record** — Identity. Community
  keeps a read model and never writes back.
- **System and transactional messaging, channel abstraction, delivery log** —
  Messaging. Chat is participant-to-participant; it is not a delivery channel
  and must not be used as one.
- **Articles, announcements, and comments on published content** — Content.
- **Physical presence at the venue: check-in, badges, access rights** —
  Admission. Community presence is an online signal and must never be treated
  as an attendance or access record.
- **Match results and standings** an achievement may be derived from —
  Competition. Community stores the award, not the sporting truth.
- **Shift attendance and staffer records** — Staffing.
- **Who registered for what** — Registration and Admission.
- **Consent, GDPR export and deletion orchestration** — Compliance. Community
  supplies its own `Gdpr/` exporter and honours deletion events.

## Public contract (events)

Events are the only way other modules may react to this one (ADR-0004).
Payloads carry ULIDs and scalars — never Eloquent models.

Community is mostly a consumer. One row is proposed; the rest is undecided.

| Event | Emitted when | Payload |
|---|---|---|
| `AchievementAwarded` (proposed) | A participant earns an achievement | `award_id`, `achievement_id`, `user_id`, `awarded_at` |

## Consumes

Events from other modules that this module listens to, and the read models it
maintains from them.

All rows below are **proposed**. Listeners must be idempotent and replayable
(ADR-0004) and must short-circuit when the feature is off for the brand
(ADR-0003).

| Event | Owning module | Why |
|---|---|---|
| User profile events (proposed) | Identity | Build and maintain the community profile read model |
| Check-in events (proposed) | Admission | Attendance-based achievements |
| Match and result events (proposed) | Competition | Competition-based achievements |
| Registration confirmation events (proposed) | Registration | Establish community membership for a brand's participants |

## Capabilities

Declared in `src/Enums/Permission.php`. Roles that grant them are per-brand
data, not code (ADR-0013).

Not yet defined. The catalog will need at least a moderation dimension
(removing messages, muting and banning participants) distinct from ordinary
participation, plus an administrative dimension for managing the achievement
catalog. The final list is decided when the module is built.

## Activation

Pennant feature: **`community`**. Registration happens at boot regardless; the
feature gates routes, navigation, UI slots, and listeners (ADR-0003).

With the feature off, a brand has no chat, no presence indicators, and no
achievements — routes and navigation entries disappear and the listeners
short-circuit, so no community read models are built for that brand at all.
That last part matters: enabling the feature later leaves a brand with an empty
community history unless the projections are backfilled (see open questions).

## Open questions

- Backfill: when a brand enables Community late, are read models rebuilt by
  replaying events, by a one-off import, or not at all?
- Chat transport: polling, broadcasting over websockets, or an external
  service. This decision drives the deployment surface and should become an ADR.
- Moderation obligations and retention: how long chat messages are kept, and
  what Compliance needs from us for reporting and deletion.
- Achievement rules: declarative definitions evaluated against incoming events,
  or code per achievement inside the module.
- Whether presence is persisted at all, or lives only in Redis.
- Whether the personal agenda (bookmarked programme items) belongs here or to
  Agenda.
