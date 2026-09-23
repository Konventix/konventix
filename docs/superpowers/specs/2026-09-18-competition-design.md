# Competition — signup, tournament desk and public pages: design

**Issues:** #32 (Anmeldung, Turnier-Desk und öffentliche Seiten), #33
(Swiss-Pairing und Katalog-Read-Model). Related: #117 (Competition Board,
gated on 26.10), #114/#115/#179 (GameOps — not in scope here), #116
(match chats — the per-match history it asks for lands here), #135
(Tournament Admin too broad).

**Status:** binding contract for the implementation plan. Deviations found
during implementation are appended to §14, never edited into the body.

**Decisions recorded here:** D-CMP-15 to D-CMP-25 and the amendment of
D-GOP-04 (ADR-0062). They were taken on 2026-09-18 against the evidence in
§2; the decision log carries the one-line versions.

---

## 1. What this builds

Slice 10 shipped the engine — `Discipline`, per-event teams, `Entrant`,
`Stage`, matches with a progression graph, five pure format generators, five
standings calculators, `EnterCompetition`, `GenerateBracket`,
`AdvanceBracket`, `RecordMatchResult`, `CorrectMatchResult`,
`PairNextSwissRound`, and the `MatchScheduled` / `MatchFinished` /
`MatchResultCorrected` contract. It shipped **no surface at all**: the
module's routes file is empty, there is no controller, no `Data` object, no
page, and `Competition` has no lifecycle beyond `published_at` and a signup
window.

This design turns the engine into the product an organizer can run a LAN's
tournament programme on, without GameOps:

1. **A competition lifecycle** an organizer drives from the console (§4).
2. **Signup** — solo and team entry, an "interested to play" list, invite
   codes, and the resolution of incomplete teams when signups close (§5).
3. **Check-in** per competition, optionally tied to the door (§6).
4. **A leaderboard format** for contests that are not brackets, and the
   stage model a later multi-stage competition will need (§7).
5. **Results** reported by participants and confirmed by their opponents,
   proofs, disputes, and orga corrections that cascade (§8).
6. **The tournament desk** and **public pages** — bracket, schedule,
   standings, match detail, team pages, "my tournaments" (§9, §10).

Out of scope and unchanged by this document: GameOps (#114, #115, #179),
the Gantt board and duration estimator (#117), the OBS overlay, chat (#116),
referees as a per-competition role, substitutes, Eventula history (§3,
D-CMP-25).

---

## 2. What the evidence says (verified 2026-09-18)

**The module.** `app-modules/competition/`: 4 migrations, 11 models, 9
Actions, 6 test files. `DisciplineKind` has four cases (`VideoGame`,
`Tabletop`, `Quiz`, `Judged`); `disciplines` carries `avg_match_minutes`,
`team_size`, `allow_draws` locally and a nullable `game_id`. `Competition`
carries `format` as a string although `Stage` also carries one — 10a's
single-flat-list shape that 10b superseded. `EntrantState` is `registered`
/ `checked_in` / `withdrawn` / `disqualified`; `MatchState` is `pending` /
`scheduled` / `live` / `finished` / `cancelled`. `competition_eligible_
registrations` is the read model fed by `RegistrationSubmitted` +
`RegistrationConfirmed`. `Permission` has six cases. No `ParticipantProfile`
exists although the SPEC lists it as owned.

**SXLAN's real programme.** The Eventula dump (`2026-09-18-001.sql`) holds
32 tournaments over SX26/SX27 and later, 27 of them real (337 participants,
66 teams):

| | Measured |
|---|---|
| Formats | 23 single elimination · 4 double elimination · 4 round robin · 1 "list" · **0 Swiss · 0 group stages** |
| Team size | 21× 1v1 · 7× 2v2 · 3× 5v5 · 1× 3v3 |
| Not a video game | 6 — Tischkicker, Tischtennis (×3), Schach, Qango |
| **Not head-to-head at all** | 4 — Casemod (jury), Trackmania (best time), Fall Guys and PUBG (points per heat) — all forced into a fake single-elimination bracket in Challonge |
| Automatable by GameOps | 4 of 27 (CS:GO / CS2) |

ADR-0024 asked for "a second example before the seam is fixed". This is
it: the non-video-game and non-paired disciplines are not speculative, they
are a fifth of SXLAN's programme, and GameOps automation reaches four
tournaments in twenty-seven. The tournament surface therefore ships fully
independent of GameOps, and the discipline — not the game — is where
contest defaults live (ADR-0062).

**Eventula tournament results** are not in the dump — matches and scores
lived in Challonge. Nothing is worth importing (D-CMP-25).

**Neighbouring contracts this design leans on.** Admission emits
`AdmissionHolderAssigned` (`admissionId`, `eventId`, `holderUserId`) and
`AdmissionCheckedIn` (`admissionId`, `eventId`, `direction`, `scannedAt`)
— together they say which *person* the door has seen, which is what §6
needs. Identity emits `UserProfileUpdated` (`userId`, `displayName`,
`username`, `visibility`) — the feed for `ParticipantProfile`. Platform's
media library (ADR-0035) is the store for proofs. Admin routes live in the
module's `routes/admin/` on the operator host (ADR-0029), public and
participant routes in `routes/web/` behind `ensure-module:competition`.

---

## 3. Decisions at a glance

| ID | Decision | Section |
|---|---|---|
| D-CMP-15 | Explicit `CompetitionStatus` with an enforced transition table; **`SignupClosed` sits between `Published` and `Running`** so an organizer can close signups without starting. | §4 |
| D-CMP-16 | A **`leaderboard`** stage format (no matches; scored entries, optionally per heat) joins the five bracket formats. **Multi-stage competitions are modelled now and built later**: every stage declares where its entrants come from and how many advance; the UI creates one stage per competition. | §7 |
| D-CMP-17 | Entry is by team with an **invite code**; captain is a `role` on the member row; **an "interested to play" list** per competition is public next to the teams; **incomplete teams are held, not rejected**, and resolved by the organizer at signup close (fill from the interested list, or decline). No join requests, no e-mail invites, no substitutes. | §5 |
| D-CMP-18 | **Check-in is configured per competition**: none / self-service window / desk, and each competition decides whether it additionally requires the roster to be checked in at the event door. | §6 |
| D-CMP-19 | **Participants report results**; a head-to-head result recorded by both sides agreeing is final and only an organizer can change it afterwards; a scored entry may carry a **proof** and needs organizer verification; **disagreement is a dispute** an organizer resolves with a mandatory comment; **an orga correction cascades** into the downstream bracket. Every step is a row in the match's history. | §8 |
| D-CMP-20 | Seeding: random by default, drag-order manual override; **regeneration allowed until the first result**. | §7.5 |
| D-CMP-21 | Scheduling in this slice: `competitions.starts_at` plus desk-set `scheduled_at` per match with a per-round bulk action. The board and the estimator stay #117. | §9 |
| D-CMP-22 | **Port** LanBrackets' canvas bracket renderer onto semantic tokens, with a table view as the accessible alternative (ADR-0055). Public pages poll; the desk broadcasts later. | §10 |
| D-CMP-23 | Capabilities gain `ManageSchedule`, `CheckInEntrants`, `ViewEntrantContacts`; the default role set splits into **Tournament Admin** and **Tournament Desk**. | §11 |
| D-CMP-24 | **Swiss stays available** with the absorbed greedy pairing; blossom remains a later decision (closes the first half of #33). | §7 |
| D-CMP-25 | **No Eventula tournament import.** | §2 |
| D-GOP-04 (amended) | **`Discipline` is the source of record for contest defaults**; GameOps' catalogue event carries identity and operational fields only. | ADR-0062 |

---

## 4. Lifecycle (D-CMP-15)

`competitions.status` (`CompetitionStatus`, string column) replaces the
implicit `published_at`-only state. `published_at` stays as the timestamp of
the transition.

```
Draft ──publish──▶ Published ──closeSignup──▶ SignupClosed ──start──▶ Running ──finish──▶ Finished
  ▲                   │  ▲                        │
  └──unpublish────────┘  └────reopenSignup────────┘
Cancelled: from Draft, Published, SignupClosed, Running (never from Finished)
```

| State | Meaning | Public page | Signup |
|---|---|---|---|
| `Draft` | Being configured. | Not visible | No |
| `Published` | Announced. Signup is **derived**: open iff the window (`signup_opens_at` ≤ now < `signup_closes_at`, either side unset = unbounded) contains now. | Visible | By window |
| `SignupClosed` | The organizer has closed signups — explicitly, or because `signup_closes_at` passed and the scheduler closed it. Incomplete teams are resolved here (§5.4). The bracket can be generated and seeded. | Visible | No |
| `Running` | The first stage has been generated and started. Results are recorded. | Visible, live | No |
| `Finished` | The last stage completed (automatic when its final result lands) or the organizer finished it. Standings are final. | Visible, final | No |
| `Cancelled` | Will not be played. | Visible, marked | No |

- Transitions are a method on the enum (`allowedTransitions()`), consulted
  by every lifecycle Action; an illegal one throws
  `IllegalCompetitionStateTransitionException`. The inventory's one
  "port as-is" verdict for LanCore's competition was exactly this.
- `Running` **requires** at least one stage generated and **refuses** while
  any entrant is `incomplete` (§5.4).
- `EventArchived` (Event) freezes `Finished`/`Cancelled` competitions as
  history; it does not add a state.
- Events emitted: `CompetitionPublished`, `SignupClosed` (with
  `entrant_count`), `CompetitionStarted`, `CompetitionFinished`,
  `CompetitionCancelled` — ULIDs and scalars. GameOps' proposed
  `CompetitionPublished` / `SignupOpened` consumers keep their names;
  `SignupOpened` is emitted when a `Published` competition's window opens
  (scheduler tick) or when the organizer reopens.

---

## 5. Signup (D-CMP-17)

### 5.1 The gate

Unchanged from D-CMP-05: a person may enter, join a team, or register
interest when the `competition_eligible_registrations` read model holds a
**confirmed** registration for them at the competition's event. Nothing
else — admission is §6's business.

### 5.2 Teams, captains, invite codes

- `competition_team_members.role`: `captain` | `member`. Exactly one captain
  per team, assigned at creation; when the captain leaves, captaincy rotates
  to the longest-standing member (LanCore's rule, kept). A solo entrant is a
  team of one whose only member is captain (D-CMP-13).
- `competition_teams.invite_code`: 8 uppercase alphanumerics without
  ambiguous glyphs (`0/O`, `1/I`), unique per brand, regenerable by the
  captain or an organizer. Joining is `POST /my/tournaments/join` with the
  code — the only self-service way into a team. Sharing it on Discord is the
  expected use.
- Roster size is bounded above by `disciplines.team_size`: a join beyond it
  is refused. No substitutes in this slice.
- Organizer powers (`ManageEntrants`): rename a team, remove a member, move
  a member between teams, add a person by user search, regenerate a code.
- A team is per event (D-CMP-14) and may enter several competitions at the
  same event.

### 5.3 The interested list

`competition_interests` (`brand_id`, `competition_id`, `user_id`, `note`
nullable ≤ 140 chars, timestamps; unique on competition + user). Registering
interest needs the §5.1 gate. The list is **public** on the competition
page next to the entered teams, showing `ParticipantProfile` display names
(and the note) so people can find each other; it is the seed for the later
per-competition chat (#116), whose audience is "interested + entered".

A user's interest row is deleted when they become a member of an entrant in
that competition, and when signups close it stays readable to the organizer
for §5.4.

### 5.4 Incomplete teams

`EntrantState` gains **`incomplete`**. `EnterCompetition` creates the entrant
as `registered` when the roster equals `team_size` and as `incomplete`
otherwise; a later join or leave moves it between the two automatically
(`SyncEntrantCompleteness`, called from the roster Actions).

- An incomplete entrant **holds a slot** against the participant limit.
  Otherwise the fill-up in the next step would have nothing to fill.
- On `closeSignup` the console shows the organizer one prompt per
  incomplete entrant with two choices, each an Action:
  - **Fill up** (`FillIncompleteEntrant`): pick people from the interested
    list (or any eligible person) until the roster is full → `registered`.
    Chosen people are notified via Messaging.
  - **Decline** (`DeclineIncompleteEntrant`): the entrant becomes
    `withdrawn` with `withdrawal_reason = incomplete_roster`; the team's
    members are notified and the slot is freed.
- `start` refuses while an `incomplete` entrant exists; bracket generation
  never seeds one.

### 5.5 Withdrawal and disqualification

A captain may withdraw the team until `Running`; the organizer may withdraw
or disqualify at any time (`ManageEntrants`). Disqualifying a seeded entrant
in a running bracket forfeits its pending matches (each recorded as a
forfeit result through `RecordMatchResult`, so the bracket advances the
normal way).

### 5.6 Linked-account requirement (addendum, 2026-09-23)

**Where it comes from.** The operator's release scoping of 2026-09-22
(event-hub design §13, row B) puts "the Steam-linked-account signup rule on
`Discipline` (`requires_linked_provider`)" into Competition for 0.9.0. It is
an eligibility default of the discipline, which ADR-0062 point 1 already
counts among the discipline's fields ("default eligibility"). ADR-0062
point 3 gave "the Steam-linked eligibility check" to the GameOps
integration (#115). This addendum narrows that sentence. The **signup rule**
is the discipline's, and GameOps may later *suggest* a value for it through
its catalogue event, as it suggests `team_size`. It never sets the value.
What GameOps keeps is `PlayerGameAccount`, the account ids a match handler
needs. Competition stores no provider account id.

**The field.** `disciplines.requires_linked_provider` is a nullable string
holding the provider key Identity uses: `steam` is the only one today, from
`Identity\Social\SteamProvider::KEY`. Null means there is no requirement. In
Competition the key is the `LinkedProvider` enum (`Steam = 'steam'`), so
validation and the generated TypeScript are closed sets. The console's
discipline form offers a select, *None* or *Steam*. Unlike `team_size`, the
field does not freeze when a competition already runs the discipline,
because switching it changes nothing that exists (see *Not retroactive*
below).

**The read model.** `competition_linked_identities`: `id` (ULID),
`user_id`, `provider`, `linked_at`, timestamps, and a unique index on
`(user_id, provider)`. Competition may not read Identity's
`social_identities` table (ADR-0004), so it projects the fact from Identity's
contract events. The table holds only the fact. It has **no
`provider_user_id`**: a SteamID64 identifies a person, and the question
"has this person linked Steam?" does not need the id. The table is **not
brand-owned**. A linked Steam account is a fact about the person. Accounts
are operator-wide (ADR-0016), and `ParticipantProfile` is kept global for
the same reason.

| Source event | What the projection does |
|---|---|
| `SocialIdentityLinked` (Identity) | Upserts the `(user_id, provider)` row. `linked_at` is the time the event was heard, because the event carries no timestamp. |
| `SocialIdentityUnlinked` (Identity) | Deletes the row. |
| `SocialIdentityAnnounced` (Identity, **new**) | Upserts the row with the `linked_at` Identity has stored. This is the backfill (see below). |
| `AccountsMerged` (Identity) | Moves the closed account's rows to the surviving account. Identity's merge moves the `SocialIdentity` rows by an update and emits no `SocialIdentityLinked`, so without this handler the survivor would lose a Steam link Identity still holds. |

Every handler is an upsert or a delete keyed on `(user_id, provider)`, so
each one is idempotent and a replay converges on the same rows (ADR-0004).

**No gate (ADR-0003; amended in fix round 1, 2026-09-23).** ADR-0003's
listener gate asks about the brand an event names, and none of these events
names one. The projection is therefore **ungated**, like Competition's other
brand-less projections (names, eligibility, event summaries). A brand that
switches `competition` on finds every link already there. The first version
gated the handlers on "`competition` is on for at least one brand". Review
rejected that: it refused people who had linked Steam, sent them to a
settings page that already showed Steam as linked, needed a second backfill
run whenever the first brand enabled the module, and ran a per-brand query
inside Identity's link transaction.

**The backfill.** `identity:announce-social-identities` is a new, minimal
Identity command. For every `social_identities` row it re-emits the narrow
`SocialIdentityAnnounced(userId, provider, linkedAt)`. It deliberately does
not replay `SocialIdentityLinked`, because that event is an
`AuditableEvent`. A replay would write one false "a Steam account was
linked" entry per identity into Compliance's audit trail, and that trail is
where an account holder looks for a key they did not add.
`AdmissionOwnershipAnnounced` and `RegistrationOwnershipAnnounced` follow the
same pattern: only a projection may listen to the event. Run it once after
the deploy that brings the table. It joins the post-rollout backfills in
`docs/operations.md`.

**Enforcement.** The rule is checked in two places, and never on a page
alone:

- **Entering** (`EnterCompetition`). Every member of the roster that enters
  must hold the provider the competition's discipline requires. This covers
  the existing members and anyone the call puts on the team. The refusal
  names the members who are missing it.
- **Joining a roster** (`MaintainsTeamRoster::addRosterMember()`). This path
  covers join-by-code, the organizer's *add member*, the fill-up at signup
  close (§5.4) and a move between teams. The person joining must hold every
  provider required by the disciplines of the competitions the team
  currently holds a slot in. This is the same set of competitions the
  roster bound (§5.2) is measured against. The refusal is for that person
  only: the rest of the team is not asked again, because they were checked
  when the team entered or when they joined. A team that has entered
  nothing has no requirement yet, so a squad can gather first and enter
  later, as it does for the roster bound.

**The desk is bound too.** An organizer's *add member*, fill-up and move
go through the same check, and the desk has no per-person override. When
somebody without the link must play anyway, the organizer switches the
discipline's requirement off, adds the person, and switches it back on.
Because the rule is not retroactive, the person stays on the roster.

The join check runs **after** §5.1 and the roster bound. The join form
answers every other refusal with one sentence, so that it cannot tell a
guesser whether a code exists. Its separate "link your Steam account"
sentence therefore reaches only a person the code would otherwise have let
in. That person learns nothing they could not learn by linking Steam and
trying again.

**Copy.** Participant copy is German. The competition page's entry panel
says that the competition needs a linked Steam account and whether the
viewer has one, with a link to the account's *Linked accounts* settings page
(`/settings/linked-accounts`). A solo discipline says "du"; a team discipline
says "alle im Team". The entry refusal names the teammates who still have to
link one. The join page shows the same link beside its
refusal. Console copy is English.

**Not retroactive.** Switching the requirement on while entrants exist
changes nothing about them. No entrant is withdrawn, marked incomplete or
flagged, and no roster member is removed. The rule applies at the next entry
and the next join. **Unlinking later removes nobody** from a roster in
0.9.0, and check-in (§6) does not ask for the link either. A competition
that must be sure at match time is the desk's call until GameOps (0.10+)
needs the account for provisioning.

**GDPR (§13).** `CompetitionGdprHandler` exports the rows as provider and
`linked_at`, and deletes them on erasure. They are a projection of Identity's
data, and nothing about a finished competition depends on them.

---

## 6. Check-in (D-CMP-18)

Two knobs on the competition:

| Column | Values | Meaning |
|---|---|---|
| `check_in_mode` | `none` / `self_service` / `desk` | `none`: every registered entrant counts as ready. `self_service`: the captain checks the team in on the participant page during a window of `check_in_opens_minutes_before` minutes before `starts_at`, closing at `start`. `desk`: only the desk checks teams in (`CheckInEntrants`). The desk can always check in or undo, whatever the mode. |
| `requires_event_check_in` | bool | If true, a team can only be checked in when **every roster member** has been seen at the event door. |

The door fact comes from a new read model `competition_admission_presence`
(`brand_id`, `event_id`, `admission_id`, `holder_user_id` nullable,
`checked_in_at` nullable), projected from six of Admission's public events:

| Event | What it contributes |
|---|---|
| `AdmissionHolderAssigned` | **Who holds it — the main path.** An ordinary attendee's admission is issued with `holder_user_id` null and is named later, when the ticket is claimed or an organizer assigns a name (ADR-0032). Nearly every row learns its holder here. |
| `AdmissionHolderUnassigned` | The admission lost its holder. The row and the scan stay; only `holder_user_id` is cleared. |
| `AdmissionCheckedIn` | That it was scanned, direction `in`. The **first** `in` scan is kept and never moved. |
| `AdmissionRevoked` | Clears the row: a revoked admission admits nobody. |
| `AdmissionIssued` | *Addition, for crew badges.* `IssueCrewAdmission` is the one path that creates an admission already assigned and names its holder here, emitting no second event — without this listen no crew member is ever at the door. |
| `AdmissionOwnershipAnnounced` | *Addition, for the #292 backfill.* The only way an admission older than this read model enters it at all: its issue and its claim happened before anything was listening. It restates the holder and the state and carries **no scan timestamp**. |

The two additions are additions, not replacements — `AdmissionHolderAssigned`
stays the main path for an ordinary attendee. Every listener is idempotent and
replayable (ADR-0004); this is the same shape Resources built for #292, and it
consumes the identical set. The check-in Action reads only this table.

`CheckInEntrant` writes `entrants.checked_in_at` and moves the state to
`checked_in`; `UndoCheckIn` reverses it. Bracket generation from a
competition with `check_in_mode ≠ none` seeds **checked-in entrants only**
(§7.5). "Ready" on every page means: `check_in_mode = none` → registered,
otherwise → checked in.

---

## 7. Formats and stages (D-CMP-16, D-CMP-24)

### 7.1 The format lives on the stage

`competitions.format` is dropped. The competition form creates the
competition **and its first stage** in one `Data` object; the stage holds
the format and its typed settings. Nothing reads `competitions.format`
except the factory and one test — both move to the stage.

Available stage formats: `single_elimination`, `double_elimination`,
`round_robin`, `group_stage`, `swiss` (greedy pairing, unchanged,
D-CMP-24), and the new **`leaderboard`**.

### 7.2 Leaderboard

A stage with **no matches**. Each entrant accumulates *score entries*:

```
competition_score_entries
  id, brand_id, stage_id, entrant_id,
  heat            smallint nullable   -- 1..n when the stage has heats, else null
  value           decimal(12,3)       -- a time in seconds, a point total, a jury score
  proof_media_id  ulid nullable       -- Platform media (ADR-0035)
  submitted_by_user_id ulid           -- participant or organizer
  verified_at, verified_by_user_id    -- null until an organizer verifies
  note            string nullable
  unique (stage_id, entrant_id, heat)
```

Typed settings (`LeaderboardSettings`, spatie Data — ADR-0009):

| Field | Values | Example |
|---|---|---|
| `direction` | `higher` / `lower` | Trackmania: `lower`; Casemod: `higher` |
| `aggregate` | `best` / `sum` / `average` | Trackmania `best`; Fall Guys `sum` over heats; Casemod `average` over jurors |
| `heats` | int ≥ 1, or null | Fall Guys: 5; Trackmania: null (one entry, resubmittable) |
| `unit` | short string | `s`, `pts` |
| `decimals` | 0..3 | |

`LeaderboardStandingsCalculator` is pure like the others: verified entries
in, ranked rows out, ties sharing a placement (standard competition
ranking). Only **verified** entries count; an organizer-submitted entry is
verified on creation. `CalculateStandings` recomputes the stage wholesale
as it already does.

This covers all four non-paired tournaments in SXLAN's history with one
table. Free-for-all *heats as matches with N slots* (a generator and a
placement-points calculator) would be the richer model for Fall Guys/PUBG
and is deliberately **not** built: a leaderboard with heats is the same
information entered by hand.

### 7.3 Multi-stage: modelled now, built later

A real tournament is rarely one bracket, and the user's examples — a
league before a double-elimination final; Trackmania qualifiers into a
final — both need entrants to *flow* between stages. The model settles that
now so the single-stage build does not have to be undone:

| On `competition_stages` | Meaning |
|---|---|
| `entry_source` | `signup` (the checked-in/registered entrants) or `previous_stage` |
| `advance_count` | how many entrants the *next* stage takes from this one — overall, or per group for a group stage (`advance_per_group`) |
| `settings` | the format's typed settings, as today |

`SeedStage` (the generalisation of `GenerateBracket`'s "resolve entrants
into seed order") takes an explicit `list<entrant_id>` seed order from a
**seed source**: `SignupSeedSource` (this slice) or `PreviousStageSeedSource`
(later — reads the previous stage's standings and takes `advance_count`).
Standings are already per stage; `CompetitionFinished` fires when the stage
with the highest `sequence` completes.

**Not built in this slice:** the second stage in the UI, the
`PreviousStageSeedSource`, cross-group ranking of runners-up. They become
one issue after #32.

### 7.4 Match sides

`match_slots.position` already allows more than two sides. Every
head-to-head path in §8 is written against "the two sides of the match",
which is the only shape a generator produces today.

### 7.5 Seeding and regeneration (D-CMP-20)

- Seed source is the ready entrants (§6). Default order is random; the
  desk can drag-order the list before generating (`seed` column) and
  re-shuffle.
- `GenerateBracket` refuses when any match of the stage has a recorded
  result — that guard stays. **Below that line, regeneration is allowed**:
  `ResetStage` deletes the stage's matches, slots and connections and
  clears `generated_at`, so a desk that spots a wrong seeding fixes it
  instead of living with it.

---

## 8. Results, proofs, disputes, corrections (D-CMP-19)

### 8.1 Two knobs on the competition

| Column | Values | Meaning |
|---|---|---|
| `result_submission_mode` | `desk_only` / `participants` | Whether participants may report. The desk can always record. |
| `proof_policy` | `none` / `optional` / `required` | Whether a report or score entry may or must carry a proof image. |

### 8.2 Head-to-head matches

```
match_result_reports
  id, brand_id, competition_match_id, entrant_id (the reporting side),
  reported_by_user_id, scores json (typed: position => score),
  proof_media_id nullable, created_at
```

1. A member of side A submits a report (`ReportMatchResult`). The match
   gains `reported_at`; the other side is notified.
2. A member of side B either **confirms** (`ConfirmMatchResult` — scores
   identical) or submits their own report with different scores.
   - Confirmed → `RecordMatchResult` runs with the agreed scores. The match
     is `finished`, `MatchFinished` fires once (D-CMP-12), the bracket
     advances. **From here only `CorrectResults` can change it.**
   - Different → the match is **disputed** (`disputed_at`), both reports
     stay, the desk is notified.
3. `desk_only` mode skips all of this: the desk records directly, as today.
4. A report on a match that already has a recorded result is refused.

### 8.3 Scored entries (leaderboard)

A participant submits a `competition_score_entries` row for their entrant
(`SubmitScoreEntry`) with an optional or required proof. It **does not
count** until an organizer verifies it (`VerifyScoreEntry`, capability
`RecordResults`), or rejects it with a note. Re-submission replaces an
unverified entry for the same heat; a verified one can only be replaced by
an organizer. Desk-entered rows are verified on creation.

### 8.4 Disputes

A disputed match, or a rejected score entry that the participant contests,
is resolved by the desk: `ResolveDispute` records the result the organizer
decides on and **requires a `resolution_note`**, which lands in the match
history (§8.6) and is shown to both sides. Capability: `RecordResults`
before a result exists, `CorrectResults` after.

### 8.5 Corrections cascade

`CorrectMatchResult` (existing) rewrites a recorded result and emits
`MatchResultCorrected`. New in this slice: if the correction changes the
winner (or, in double elimination, the loser) of a match whose successors
have already been populated, the Action **re-routes** the affected
downstream slots through the progression graph — and every downstream match
that already has a recorded result on a changed slot is **voided**: its
result is cleared, its state returns to `pending`/`scheduled`, a
`match_history` row records `voided_by_correction` with the correcting
organizer's mandatory `note`, and the standings are recomputed. The user's
words: a correction "results in new matches/pairings". `MatchFinished` is
**not** re-emitted for a voided match when it is played again — the
`finished_event_emitted_at` guard keeps D-CMP-12 true; GameOps' teardown
happened once and is not owed a second time. (A voided match that GameOps
provisioned is a desk problem in this slice and a GameOps rule under #115.)

### 8.6 Match history

```
competition_match_history
  id, brand_id, competition_match_id, kind, actor_user_id nullable,
  payload json (typed per kind), created_at
```

Kinds: `scheduled`, `rescheduled`, `checked_in`, `reported`, `confirmed`,
`disputed`, `resolved`, `recorded`, `corrected`, `voided_by_correction`,
`cancelled`, and later `session_live` / `session_failed` from GameOps. This
is the "nachvollziehbare Historie pro Match" #116 says is the valuable part
of match chat, delivered without the chat. It is shown on the match detail
page to the sides and the desk.

### 8.7 Proof images

Proofs use Platform's media library (ADR-0035) with a private disk: a
participant upload is scoped to the brand, ≤ 8 MB, image types only, never
SVG (#252), and readable only by the two sides and the desk through a
proxied route. The implementation must confirm the library supports
participant-originated uploads with per-object read authorization; if it
does not, that becomes a Platform change *before* proofs ship, not a
Competition workaround.

---

## 9. The tournament desk (D-CMP-21)

Console, operator host, `routes/admin/competition.php`:

| Page | Capability | Contents |
|---|---|---|
| `admin/disciplines` | `ManageDisciplines` | Discipline CRUD: kind, name, team size, avg match minutes, draws, optional GameOps game (picker read model, ADR-0062). |
| `admin/competitions` | `ViewCompetitions` | Per event: list with status, entrants/limit, next action. |
| `admin/competitions/create`, `…/{id}/edit` | `ManageCompetitions` | One `Data` object: name, slug, discipline, description (markdown), rules (markdown), `max_entrants`, signup window, `starts_at`, check-in knobs (§6), result knobs (§8.1), first stage format + typed settings. Lifecycle buttons per §4. |
| `…/{id}/entrants` | `ManageEntrants` | Teams and rosters, interested list, incomplete-team prompts at close (§5.4), withdraw/disqualify, contact view (`ViewEntrantContacts`). |
| `…/{id}/desk` | `CheckInEntrants`, `RecordResults`, `CorrectResults`, `ManageSchedule` | Check-in list; seed order (drag), generate/reset; the bracket or table; per-match record/correct/resolve dialogs; per-round "schedule all at" and per-match `scheduled_at`; for a leaderboard, the verification queue. |

Every desk write is an Action; every page one `Data` object with
generated TS types (ADR-0009); every screen registers its nav entry with
Platform and honours the module gate (ADR-0003).

---

## 10. Public and participant pages (D-CMP-22)

Brand site, `routes/web/competition.php`:

| Route | Page |
|---|---|
| `/events/{event}/tournaments` | All published competitions of the event: discipline, format, team size, status, entrants/limit, starts at. |
| `/events/{event}/tournaments/{competition}` | Description and rules, entered teams with rosters, the interested list, bracket (canvas + table tab) or leaderboard, standings, schedule; the entry / interest / join-by-code call-to-actions for a logged-in eligible person. |
| `…/{competition}/matches/{match}` | Sides, scores, `scheduled_at`, history (§8.6); report/confirm/dispute for a member of a side. |
| `…/{competition}/teams/{team}` | Roster and the team's matches across the event. |
| `/my/tournaments` | The person's teams and entries at upcoming events: invite code (captain), leave, withdraw, check in (self-service window), pending reports to confirm, score entries and their verification state. |
| `/my/tournaments/join` | Join by invite code. |

**Bracket rendering.** LanBrackets' `useBracketCanvas.ts` (758 LOC) is
ported into `app-modules/competition/resources/js/` and re-themed onto
semantic tokens (ADR-0008); the table view of the same rounds is the
keyboard- and screen-reader-accessible alternative (ADR-0055) and is the
default on narrow viewports. The canvas reads the same `BracketData` the
table does.

**Freshness.** Public and participant pages use Inertia polling (30 s)
while the competition is `Running`; nothing else. Reverb broadcasting for
the desk is a follow-up, not a dependency.

**Names.** `ParticipantProfile` (`competition_participant_profiles`:
`user_id`, `display_name`, `username`, `visibility`) is projected from
`UserProfileUpdated`; every page shows names from it, never from Identity.

---

## 10a. Hub cards (addendum, 2026-09-23)

The event-hub design (`2026-09-22-event-hub-design.md` §8) gives Competition
two cards on `/live` and points here for them. Its D-HUB-07:

> "Next Games" is Competition's feature (my upcoming matches, nudging, no
> enforcement) and works with hand-run servers; GameOps only enriches it with
> connect details in 0.10+.

The pain behind it: guests enter several competitions, fall behind on their
matches and hold everyone up. The cards nudge; they never block, and nothing
is pushed to a phone.

**Registration.** `CompetitionServiceProvider::registerHub()` adds both
cards under module `competition`, so the registry hides them for a brand
with the module off (ADR-0003). The hub already limits itself to admitted
participants, and there is no capability. Orders are 40 and 41, after
Agenda's *Jetzt / Gleich* (20) and Community's *Shoutbox* (30). Each payload
is built by an invokable resolver in `src/Hub/` that reads only
Competition's own tables and returns a `#[TypeScript]` `Data`.

**Nächste Spiele** (`competition.next-games`,
`competition/hub/NextGamesCard`). Lists the viewer's open matches in the
active event's competitions. A match counts when one of the viewer's active
entrants (`registered` or `checked_in`, on a team they are on) holds a slot,
the match is `pending`, `scheduled` or `live`, and its competition is
neither a draft nor over. Order: scheduled before unscheduled, then
soonest `scheduled_at`, then round, then position. Each row shows the
competition, the round (section and round number, like the match page's
heading), the opponent's team name or *wartet auf Gegner* while the slot
waits on an undecided match, the time, a nudge, and a link to the match
page. The card shows at most five rows and links to *Meine Turniere*.

The nudge comes from data the match page already reads, and the card and
page ask the same rule for it (`Pages\MatchPowers`), so they cannot
disagree. When several apply, the first one wins:

1. *Streitfall offen*: the match is disputed and the desk has not decided.
2. *Ergebnis bestätigen*: the other side reported and the viewer's side may
   confirm. The controller's list had no name for this state. It was added
   because it is the case the operator described: the opponent is waiting.
3. *Wartet auf Bestätigung*: the viewer's side reported and the other side
   has not answered.
4. *Ergebnis melden*: the viewer's side may report, and the match is either
   due (`scheduled_at` ≤ now) or has no time. Reporting is the side's next
   act under §8.2, so an overdue match nobody has reported keeps asking for
   the report rather than saying *Jetzt dran* forever. With hand-run servers
   you play when you can and then report.
5. *Jetzt dran*: `scheduled_at` ≤ now and every side has arrived, but the
   side cannot report: a `desk_only` competition, or one whose proof the desk
   collects. Here the side's next act is to play; the desk records.

A match scheduled later, or one still waiting for its opponent, carries no
nudge. (Fix round 1 swapped rules 4 and 5. At first *Jetzt dran* outranked
the report and *Ergebnis melden* applied only to unscheduled matches, so an
overdue match stayed on *Jetzt dran* until someone acted.)

**Meine Competitions** (`competition.my-competitions`,
`competition/hub/MyCompetitionsCard`). Lists the viewer's entries in the
active event's competitions: every slot-holding entrant (`incomplete`,
`registered`, `checked_in`) of a non-draft competition, with competitions
that have a start time first, soonest first, then by name. Each row shows
the name, the status in German (the public pages' labels), the check-in
state when the competition's `check_in_mode` requires one, and a link to the
competition page. The card shows at most five rows plus *Meine Turniere*.
When the viewer has no entries, the card shows one sentence and a link to
the event's tournament index.

**Time** (ADR-0027). `scheduled_at` is an instant: the desk types it in its
own zone and it is stored anchored to UTC. The hall reads it on the event's
wall clock. Competition projects the event's IANA timezone into
`competition_event_summaries.timezone`: `EventCreated` carries it, and so
does `EventRescheduled`, which `event:replay-details` re-emits for every
event, so rows that predate the column are backfilled. The card sends the
instant plus `eventTimezone`, and the match page sends `event.timezone` on
its `EventRefData`. Both render through `formatEventDateTime()`
(`components/public/eventTime.ts`), which is `formatDateTime()` with the
event's zone. A match at 18:00 UTC at a Europe/Berlin event reads 19:00 in
November (CET) and 20:00 in July (CEST), whoever is looking. Without a
projected zone, both fall back to the reader's zone.

The first version of this paragraph said the timezone could not be
projected. That was wrong, and fix round 1 corrected it. The final review's
fix wave B put every other time on the tournament pages on the same clock:
the competition page's start, schedule and bracket table, the event's
tournament index, the team page, and "Check-in ab …" on *Meine Turniere*,
whose controller sends each team's event zone (final review, part 3, I1).

**Liveness** (hub design §7.1). *Nächste Spiele* carries `pollSeconds`: 60
while any competition the viewer has an active entry in is `Running`,
otherwise null. While it is set, the card fetches its own payload as JSON
from `nextGamesUrl` (`GET /live/next-games`, `NextGamesController`) into
local state. This follows the shoutbox card's `messagesUrl`. The endpoint
runs behind `auth`, the module gate, and a named `competition-hub` budget
(12 a minute per account). It uses the hub's own gate: it answers with a
404 when `ActiveEventContext` has no running event for the member. The rest
of the hub is never rebuilt for a refresh, and `platform_hub_views_total`
keeps counting hub renders, not polls. *Meine Competitions* does not poll.
No Reverb in 0.9.0.

---

## 11. Capabilities and roles (D-CMP-23)

`Permission` gains `ManageSchedule`, `CheckInEntrants`,
`ViewEntrantContacts`. Default role sets (ADR-0045):

| Role | Grants |
|---|---|
| Tournament Admin | all nine |
| Tournament Desk | `ViewCompetitions`, `CheckInEntrants`, `RecordResults`, `ManageSchedule` |

`CorrectResults` and `ManageEntrants` stay with the admin — the two
operations with a blast radius (#135). Participant actions (enter, join,
report, confirm, check in) are **not** permissions: they derive from
membership in a side, captaincy, and the §5.1 gate.

---

## 12. Events

*(Reconciled against the code in Task 8: §14, "§12 reconciled against the
code", is the list as emitted, with the real payloads. `SPEC.md` carries it.)*

Added to the public contract (all ULIDs and scalars):

| Event | Payload |
|---|---|
| `CompetitionPublished`, `CompetitionStarted`, `CompetitionFinished`, `CompetitionCancelled` | `competition_id`, `event_id`, `discipline_id`, `status`, `at` |
| `SignupOpened`, `SignupClosed` | `competition_id`, `event_id`, `at`, `entrant_count` |
| `EntrantRegistered`, `EntrantWithdrawn`, `EntrantCheckedIn` | `entrant_id`, `competition_id`, `team_id`, `member_user_ids`, `reason` (withdrawn) |
| `MatchResultReported`, `MatchDisputed`, `MatchDisputeResolved` | `match_id`, `competition_id`, `entrant_id`, `user_id`, `at` |
| `MatchRescheduled`, `MatchCancelled` | as proposed in the SPEC |

Consumed (new listeners, all idempotent): `AdmissionHolderAssigned`,
`AdmissionHolderUnassigned`, `AdmissionCheckedIn`, `AdmissionRevoked` (§6);
`UserProfileUpdated` (§10). Messaging is used, never listened to: fill-up
and decline notices, "your opponent reported a result", "a match was
disputed", "checked in" — through `SendMessage` with templates owned by
this module.

`MatchScheduled`'s payload gains `member_user_ids` per side, which #179 asks
for (roster and Steam IDs); it is additive.

---

## 13. Observability, GDPR, tests

- Metrics (ADR-0059): `competition_entrants_total{state}`,
  `competition_matches_total{state}`, `competition_disputes_open`,
  `competition_score_entries_unverified`; a dashboard row; an alert on a
  dispute older than 30 min while the competition is `Running`.
- `CompetitionGdprHandler` exports interest rows, team memberships, reports,
  score entries and history rows for a subject; anonymises
  `ParticipantProfile` and `actor_user_id` on deletion.
- Definition-of-done tests (from #32): one Pest flow per archetype — a 2v2
  single-elimination CS tournament with an incomplete team filled from the
  interested list, participant-reported and confirmed results, one dispute
  and one cascading correction; and a Trackmania leaderboard with proofs
  and verification. Plus the feature-off test: every listener short-circuits
  with `competition` off for the brand.

---

## 14. Deviations found during implementation

*(append only)*

### Unpublishing a competition tells nobody (found in Task 1, 2026-09-23)

`UnpublishCompetition` emits no event, because §12 names none — correctly, since
inventing a contract name the design never settled would be worse. The
consequence belongs here rather than in a task report: **a consumer that
projected the competition when it was published keeps it.** Sub-project C's
timetable is the first such consumer — its competition half (waiting on #296)
selects competitions into the published programme and projects
`CompetitionPublished` / `CompetitionRescheduled` / `CompetitionCancelled`. An
organizer who unpublishes a competition would leave its block standing in a
programme the public can read.

Closing it is additive and cheap: a `CompetitionUnpublished(competitionId,
eventId, brandId, unpublishedAt)` event emitted by `UnpublishCompetition`, which
C's projection removes the row on. It is recorded rather than built because the
consumer does not exist yet and B should not invent a contract for a projection
nobody has written — but **the competition half of the timetable must not ship
without it.**

### §6, 2026-09-23 — door presence also listens to `AdmissionIssued` and `AdmissionOwnershipAnnounced`

*(Corrected 2026-09-23 in the fix round on this task: the first version of this
entry gave a reason that is not true of the code. It claimed a self-bought
ticket names its holder on `AdmissionIssued`. It does not. §6's own
source-event list was amended in the same round and now carries all six events
with a clause each, so a reader who never reaches §14 still gets the true
list — GameOps builds on it in 0.10. This entry records why it changed.)*

**§6 is right about the main path.** `AdmissionHolderAssigned` is how an
ordinary attendee's admission gets a holder, and it stays the main path.
`Admission\Actions\IssueAdmissionsForRegistration` writes `holder_user_id`
**null** on every registration-derived admission and emits
`AdmissionIssued(holderUserId: null)`; the holder is named afterwards by
`ClaimAdmission` or `AssignHolder`, and those emit `AdmissionHolderAssigned`
(ADR-0032). Nearly every row in `competition_admission_presence` learns who
holds it there.

`Listeners\ProjectAdmissionPresence` nevertheless consumes two further events,
as **additions** to §6's list and not as a replacement for it:

1. **`AdmissionIssued`, for crew badges.**
   `Admission\Actions\IssueCrewAdmission` is the one path that creates an
   admission already `Assigned` — a badge belongs to a named person from the
   moment it exists — and names the holder on `AdmissionIssued` alone,
   emitting no second event. Without this listen no crew member is ever at the
   door as far as this read model is concerned.
2. **`AdmissionOwnershipAnnounced`, for the backfill** (#292's
   `admission:announce-ownership`). It is the only way an admission that
   predates this read model enters it at all: its issue and its claim happened
   before anything was listening.

Both are strictly additive, both are public events, and Resources'
`ProjectAdmission` consumes the identical five events
(`ResourcesServiceProvider`) for the identical two reasons.

### §6, 2026-09-23 — what the module gate on door presence costs

The six presence listens short-circuit when `competition` is off for the
brand the event names (ADR-0003, #298's second acceptance criterion), unlike
the eligibility and name projections, which are ungated so a brand switching
the module on mid-event finds them filled.

Recorded because the trade-off is **not** "cheap to miss".
`AdmissionOwnershipAnnounced` restates who holds an admission and whether it
stands, and carries **no scan timestamp**. A brand that enables `competition`
after the doors opened therefore gets its rows and its holders back from the
backfill and can never get `checked_in_at` back — every
`requires_event_check_in` team is refused until each of its members is
physically re-scanned at the entrance. The gate is mandated and stays; the
recovery path belongs in the operator documentation (§13's slice).

### §6, 2026-09-23 — check-in desk on its own route, not `…/{id}/desk`

§9 puts the check-in list inside the tournament desk. The desk is §9's own
slice; §6's slice shipped the list at `/admin/competitions/{competition}/check-in`
(`Admin\CheckInDeskController`, `admin/competitions/desk/CheckIn.vue`) so the
two do not race for one controller. §9's slice may fold the panel in or link
to it.

### §6, 2026-09-23 — a self-service window needs a `starts_at`

§6 defines the window as "`check_in_opens_minutes_before` minutes before
`starts_at`". With `starts_at` unset there is no anchor, so
`Competition::selfServiceCheckInIsOpen()` answers **false** rather than
treating the missing bound as unbounded (which is what §4 does for the signup
window). The desk can check teams in whatever the mode, so nobody is locked
out; the console says so on the check-in page.

### §6, 2026-09-23 — no `EntrantCheckedInUndone` event

`UndoCheckIn` emits nothing. §12 names `EntrantCheckedIn` and no counterpart,
and a "never mind" travelling as a contract event would be consumed as a fact
in its own right. Worth revisiting if a consumer ever provisions on
`EntrantCheckedIn`.

### §8.1, 2026-09-23 — `result_submission_mode` was enforced nowhere

§8.1 names two knobs. The leaderboard slice implemented `proof_policy` and
left the other one stored but unread: a repo-wide search found
`result_submission_mode` only in the admin form, the `Data` object and the two
lifecycle Actions that write it. Nothing consulted it, so a `desk_only`
competition's verification queue filled with numbers typed by the competitors
and, once §8.2's report path existed, would have taken their match reports
too.

Both submission Actions now ask it — `ReportMatchResult` refuses a participant
report and `SubmitScoreEntry` refuses a participant score entry, while the
desk path (`asOrganizer`) is never refused, because "the desk can always
record". The refusal is in the Action and not only on the form, on the same
standing as every other guard in this module: a client that ignores the
interface must still be turned away.

The column defaults to `desk_only`, so this changed the meaning of every
existing test that submitted a score as a participant against a factory-made
competition. `LeaderboardTest`'s stage helper now sets `participants`
explicitly rather than inheriting the default.

### §8.2, 2026-09-23 — a counter-report that agrees is a confirmation

§8.2 draws step 2 as two branches: the other side **confirms**, or it
**reports different scores** and the match is disputed. The implementation
adds the third case the two branches leave out — the other side reports the
*same* scores. Treating that as a disagreement would leave a match stuck
because both sides pressed "report" instead of "confirm", which is a bug and
not a rule, so `ReportMatchResult` compares the two claims and finalises when
they agree. `ConfirmMatchResult` remains a separate Action and takes no
scores at all: a confirming side saying "what they wrote is right" should not
be re-typing numbers it could mistype.

Two consequences worth naming. Reports are keyed by **slot position**, as
§8.2 writes them, because a correction re-routes entrants through the
progression graph (§8.5) and the slot is the stable thing over a match's life;
comparing two claims is then an identity comparison of two maps with the same
keys. And a side that reports twice replaces its own row rather than filing a
second claim — a unique index on `(competition_match_id, entrant_id)` says the
same thing — so the table carries `updated_at` where §8.2's sketch named only
`created_at`.

### §8.4, 2026-09-23 — the desk hears about a dispute from its screen, not a message

§8.2 says "the desk is notified" when a match is disputed, and §12 lists
Messaging as the channel for it. Competition has no list of who is on the
desk: capabilities are per-brand role rows (ADR-0013) and `SendMessage`
addresses one person. The opposing **roster** is notified when a result is
reported (`competition.result-reported`), which is the half §8.2 can address;
the desk's half is §9's open-dispute panel and §13's
`competition_disputes_open` alert, both of which want to exist anyway.

Read the other way round, §8.4's two capabilities settle something §8.2 leaves
open: "`RecordResults` before a result exists, `CorrectResults` after" only
means anything if a side can contest a result that is **already recorded**. So
a *report* on a finished match is refused (§8.2, point 4) and a *dispute* on
one is not — and resolving that dispute runs as a correction, with §8.5's
cascade and no second `MatchFinished`.

Also unbuilt from §8.4: the second thing it says can be disputed, "a rejected
score entry that the participant contests". `RejectScoreEntry` records the
rejection with its mandatory note and the participant can resubmit, but a
leaderboard stage has no match to hang a dispute row on, and inventing a
second dispute table for it would have been a bigger decision than this slice
should take alone. The desk can verify an entry it rejected in error.

### §8.5, 2026-09-23 — the correction note is mandatory exactly when something is voided

§8.5 attaches the organizer's "mandatory `note`" to the
`voided_by_correction` history row. A correction that changes nobody's
position downstream — a rewritten score line with the same winner, or a
correction in the last round — voids nothing and therefore owes no note, and
the desk cannot know in advance which kind it is making. `CorrectMatchResult`
therefore walks the successors and refuses the moment it finds the first
result it would have to take away without a reason; the whole correction runs
in one transaction, so a refusal leaves it unmade rather than half made.

Two things the void does beyond what §8.5 lists, both for the same reason —
the match is now between different teams:

- the claims filed on it are **deleted**, because confirming one of them
  afterwards would record a result nobody played;
- `reported_at`, `disputed_at` and `dispute_resolved_at` are cleared.

And one thing it deliberately does not do: `finished_event_emitted_at` is
untouched, which is the whole of D-CMP-12 and is what `CorrectionCascadeTest`
pins.

`AdvanceBracket` was left alone. It fills slots and skips an edge whose
outcome is null, because "not known yet" is the normal state of a bracket
being played; a correction is the one caller for which a null outcome means
the opposite — somebody who *was* there no longer belongs — so the emptying
lives in `CorrectMatchResult` beside it rather than changing what advancing
means for every other caller.

### §8.6, 2026-09-23 — three kinds ship unwritten

`MatchHistoryKind` carries all eleven kinds §8.6 lists plus GameOps'
`session_live` and `session_failed`, so a rendered timeline does not change
shape in 0.10 and the two modules cannot disagree about what the kinds are
called. Nothing in 0.9 writes those two, and nothing writes `checked_in`
either: check-in in this slice is per competition (§6), not per match. A test
asserts the case list and that a match played end to end writes none of the
three.

`ScheduleMatch` writes `scheduled` and `rescheduled` but still emits neither
`MatchRescheduled` nor `MatchCancelled` — §12 lists both as "as proposed in
the SPEC", and a match cancellation has no Action at all yet. They belong with
§9's scheduling panel.

### §8.7, 2026-09-23 — the library takes the upload; it does not yet guard the read

§8.7 asks the implementation to "confirm the library supports
participant-originated uploads with per-object read authorization; if it does
not, that becomes a Platform change *before* proofs ship, not a Competition
workaround". It confirms half of it.

**Uploads.** Platform's `StoreMediaUpload` takes an explicit brand and an
uploader, so nothing in it is console-only; what was console-only was the
*door*. `Actions\StoreProofImage` is the participant's door: it checks the
competition's `proof_policy` and the caller's membership in the side, then
hands the file to Platform's Action. Competition stores no file, knows no disk
and builds no path (ADR-0035). The HTTP endpoint is
`POST /tournaments/{competition}/proofs`, `auth` only, with §8.7's bounds —
8 MB, image types, never SVG (#252).

**The gate has to be at the door, not at the submission.** `proof_media_id` is
a ULID, so by the time `SubmitScoreEntry` or `ReportMatchResult` can refuse a
proof the image is already written. A `proof_policy` of `none` — the column's
default — checked only at submission time would accept the upload, reject the
participant's whole result over the attachment, and leave an orphaned object
behind. The policy is therefore asked before a byte is stored; the two
submission guards stay as backstops. `ProofPolicy::None` **refuses** rather
than ignores, because §8.1's three values are the three answers to "may or
must a result carry a proof" and a `none` that accepted and dropped the image
would be `optional` under another name.

**Reads are owed.** `MediaFileController` is deliberately unauthenticated and
brand-agnostic (its own docblock says so), there is no per-item privacy flag,
and no private disk is configured for media. So §8.7's "readable only by the
two sides and the desk through a proxied route" does not hold: a proof is
readable by anyone holding its ULID. That is the Platform change §8.7
mandates, and it is recorded as owed in Competition's `TODO.md`. Nothing in
this module moves when it lands — a media ULID is a media ULID whichever door
it came in through — but **the participant-facing upload control should not
ship until it does** (§10's slice).

### §9, 2026-09-23 — the draw is `ManageSchedule`, and it arrives with the desk

§9 gives the desk page four capabilities and does not say which of them the
seed order, generate and reset sit behind. None of `CheckInEntrants`,
`RecordResults` or `CorrectResults` is about arranging who plays whom, and
§11 keeps `ManageEntrants` and `CorrectResults` with the admin as the two
operations with a blast radius; so the draw is `ManageSchedule`, the other
half of "who plays whom, and when". The Tournament Desk role §11 sketches
(`ViewCompetitions`, `CheckInEntrants`, `RecordResults`, `ManageSchedule`)
can therefore run a tournament end to end except for corrections, which is
what #135 asked for. A reset is harmless under this reading because
`ResetStage` refuses once the first result is recorded (D-CMP-20).

`ManageSchedule` itself is added by the desk's slice rather than §11's pass,
for the reason `CheckInEntrants` was added by §6's: the desk actions it
gates exist now. `ViewEntrantContacts` and the role split stay with §11.

Two smaller things the desk decides. Recording over a **disputed** match is
refused (`RecordResultAtDesk` → "resolve it instead"), because §8.4 owes both
sides the note a plain record would skip. And the per-round "schedule all at"
is one instant for every open match of `(bracket, round)`, skipping matches
already at that instant so their timeline does not gain a `rescheduled` row
that says nothing changed; staggering across stations is #117's estimator.

### §4/§7.5, 2026-09-23 — where in the lifecycle the desk may draw, empty and play

§4 says the bracket "can be generated and seeded" in `SignupClosed` and that
`Running` **requires** a generated stage; D-CMP-20 allows a redraw until the
first result. `Concerns\GuardsCompetitionLifecycle` reads those three
sentences as rules, locking the competition row like the state writer does:

- **seed, shuffle, generate, draw again** — `SignupClosed`, and `Running`
  until the first result. Never `Draft` or `Published`: signup may still add
  an entrant the bracket would leave out.
- **reset** — `SignupClosed` only. A reset in `Running` would leave a running
  competition with no bracket; "draw again" replaces it in one transaction
  and keeps §4's invariant, so that is the button there.
- **schedule, record, correct, resolve, verify, reject, enter a score** —
  anything but `Finished` and `Cancelled`, which are history.

"The first result" now includes a **verified score entry** on a leaderboard,
which has no matches for the old reading to find (§7.2: only verified
entries count). The previous entry's "a reset is harmless" holds with both.

The desk also refuses to finish a match whose other side is still to come (an
empty slot fed by an undecided match) while still settling a **bye** — an
empty slot nothing will ever fill, which is how the generators leave the top
seeds' byes — and refuses a draw on an elimination bracket or wherever the
discipline or the format's settings have none.

### §10, 2026-09-23 — `{event}` is the event's slug, resolved against Competition's own copy

§10 writes `/events/{event}/tournaments`. The event landing page is
`/events/{slug}`, so the tournament pages hang off the same slug — but
Competition may not read Event's model (ADR-0004), and the slug is not on any
of its own rows. `competition_event_summaries` (event ULID, slug, name) is
projected from `EventCreated` and `EventDetailsUpdated` by
`Listeners\ProjectEventSummary`, ungated like the eligibility and name
projections, and `event:replay-details` backfills it — the same shape Agenda
and Admission already keep. The competition is addressed by its own slug,
unique per brand; a match and a team by their ULIDs.

### §10, 2026-09-23 — what the canvas port kept and changed

`useBracketCanvas.ts` is ported into `resources/js/components/bracket/`
(layout in `bracketModel.ts`, drawing in `useBracketCanvas.ts`). Kept: the
match box, its two rows, the bezier connectors, pan, wheel zoom, pinch,
fit-to-screen, the section labels of double elimination. Changed:

- colours are the brand's semantic tokens, read from the element's computed
  style on every draw (ADR-0008);
- the connectors are the progression graph (`BracketData.connections`,
  winner edges), not LanBrackets' "match `i` feeds match `i / 2`" guess;
- the layout is generalised from its two hard-coded shapes to "sections
  stacked, grand final to the right", so a group stage and round robin draw
  too;
- it draws on change, not on every animation frame: the pulsing "ready" glow
  and the medal emoji are gone (a still warning border says "ready"; the
  standings table says who placed);
- a click on a match opens its page.

The table (`BracketTable.vue`) and the canvas both take
`bracketSections()` of the same `BracketData`; `bracketModel.test.ts` pins
that they place the same pairings in the same rounds. The table is the
server-rendered default and stays the default below 768 px.

### §10, 2026-09-23 — the participant writes, and what a `required` proof does to them

None of §8's participant Actions had a route. They are routed from the pages
that offer them: `POST /tournaments/{competition}/entries` (a captain enters a
team), `…/score-entries`, `…/matches/{match}/report|confirm|dispute`. All are
`auth` plus `throttle:6,1`, the proof upload's budget — which, keyed by the
signed-in user, is one budget of six a minute across all of them. The entrant
or side is always the viewer's own, derived from their roster, never read
from the request; somebody on no side gets a 404.

There is no proof upload control on any screen until Platform can guard who
reads a proof (#322, §8.7). A competition whose `proof_policy` is `required`
therefore offers its participants no report or score form at all — a report
without the owed proof would only be refused — and says that the desk
collects the result and the proof. Confirm and dispute stay.

§8.6 shows a match's history "to the sides and the desk": the public match
page sends it only to a member of a side.


### §10, 2026-09-23 (fix round) — a draft event's tournaments are not public; one named budget; §5.1 and `desk_only` enforced on the server

**The event's own state gates the pages.** A published competition at a
draft event was reachable under `/events/{slug}/tournaments`, and the index
answered 200 with a draft event's name. Event's landing page 404s a draft,
and Agenda's programme serves only `published`, `running` and `concluded`.
`competition_event_summaries` now carries the event's lifecycle `state`,
projected from Event's seven lifecycle events with Agenda's mapping, and the
pages serve Agenda's set — `draft` and `announced` are the organizer's
workbench, `cancelled` and `archived` are over, and `concluded` stays up as a
record. A null state (not yet told) is refused; `event:replay-details
--with-state` backfills it.

**One named budget for every participant write.** The earlier entry's
`throttle:6,1` has an unprefixed key — the account alone — so it was one
counter shared with every other unnamed throttle in the application. All
fourteen participant writes of this module, the older signup ones (join by
code, create team, leave, invite code, withdraw, check-in, interest) and the
proof upload included, now spend `throttle:competition-participant`: ten a
minute per account, named in `CompetitionServiceProvider`.

**§5.1 on entering is checked on the server.** `EnterCompetition` checks
eligibility only for the members it is handed, and the entry route handed
none, so only the page hid the form. The route now hands it the whole roster,
so every member's confirmed registration is checked, and it answers a refusal
with one German sentence.

**No dispute in `desk_only`.** §8.1's `desk_only` means the sides submit
nothing. `DisputeMatchResult` now refuses there, as a report is refused, and
the match page offers no dispute. An objection to a desk-recorded result is
made at the desk.

**The desk's exceptions never reach a participant in English.** A
participant can reach `CompetitionLifecycleException::over()` from a page
opened before the competition finished, and `IllegalMatchStateTransitionException`
when a match moved on under them. Each is answered with one fixed German
sentence on the form's field.

### §12, 2026-09-23 — §12 reconciled against the code (Task 8)

§12 was written before the code, and the plan left the reconciliation to the
last task on purpose. What tasks 1 to 7c emit was compared with §12 event by
event. Where the code is right, this entry corrects the design. Where §12 is
right, the code was changed in the same task. `SPEC.md`'s contract table now
lists what is emitted, with its real payload.

| Event | §12 says | The code | Decision |
|---|---|---|---|
| `CompetitionPublished` | `competition_id`, `event_id`, `discipline_id`, `status`, `at` | Adds `brand_id`, `name`, `starts_at` | Code is right. `brand_id` is what a listener gates on (ADR-0003), and the name and start are what Agenda's timetable projects. |
| `CompetitionStarted` / `Finished` / `Cancelled` | As above | Adds `brand_id` | Code is right, for the same reason. |
| `CompetitionRescheduled` | Not listed | Emitted by `UpdateCompetition` when an announced competition's `starts_at` moves | Code is right. It is additive and Agenda's timetable reads it. |
| `CompetitionUnpublished` | Not listed | Not emitted | Stays unemitted in 0.9.0, because nothing consumes a competition's publication yet. It is the precondition of Agenda's competition half, and Agenda's `TODO.md` now says so (see "Unpublishing a competition tells nobody" above). |
| `SignupOpened` | Emitted when the window opens (scheduler tick) or the organizer reopens | Emitted on publish into an open window and on reopen. There is no scheduler tick, and `brand_id` is added. | §12 is right about the tick, and the tick is not built. Signup is derived from the window (§4), so entering works on time. Only the event is late or missing: a competition published before its window opens emits no `SignupOpened` when the window arrives. Owed in `TODO.md`. |
| `SignupClosed` | `competition_id`, `event_id`, `at`, `entrant_count` | As listed, plus `brand_id`. Emitted by the organizer's close only. | Same as above: nothing closes a competition automatically when `signup_closes_at` passes. Signup is shut by the window anyway, but the status stays `Published` until the organizer closes it. Owed in `TODO.md`. |
| `EntrantRegistered` | `entrant_id`, `competition_id`, `team_id`, `member_user_ids` | `competitionTeamId`, plus `event_id`, `brand_id`, `registered_at` and `state` | Code is right. `state` is there because an incomplete roster enters too (§5.4) and is announced as `incomplete`. A later fill-up that completes it emits nothing, so a consumer that needs the full roster reads it from `MatchScheduled`. |
| `EntrantWithdrawn` | `…`, `reason` | Adds `event_id`, `brand_id`, `final_state` (`withdrawn` or `disqualified`), `withdrawn_at` | Code is right. |
| `EntrantCheckedIn` | `…` | Adds `event_id`, `brand_id`, `checked_in_at`, `via_self_service` | Code is right. There is no undo event (see §6's entry above). |
| `MatchResultReported`, `MatchDisputed` | `match_id`, `competition_id`, `entrant_id`, `user_id`, `at` | Adds `brand_id` | Code is right. |
| `MatchDisputeResolved` | As above | Adds `brand_id`; `entrant_id` is the side the decision went to, and null for a draw | Code is right. The resolution note is left off the payload deliberately (`SPEC.md`). |
| `MatchRescheduled`, `MatchCancelled` | "As proposed in the SPEC" | Neither is emitted | They stay proposed. A reschedule already emits `MatchScheduled` again with the new time, which is the upsert a consumer needs. *(Corrected in the fix round: an earlier version said no Action cancels a single match. Three paths retract an announced match without an event; see "`MatchScheduled` has no retraction yet" below.)* GameOps' consumer (#179, 0.10) adds `MatchCancelled`. |
| `MatchScheduled` | Gains `member_user_ids` per side | It had, but it was emitted for a match with an empty slot | **§12 and ADR-0012 are right, and the code was changed.** The event means "this match is on, at this time, between these sides". GameOps configures a server with the rosters, so a match with a side still to come has nothing to provision. `ScheduleMatch` now emits it only when every slot holds a side. `AdvanceBracket` emits it when it fills the last slot of a match the desk has already timed, which is the common case: the desk times round two before round one is decided. `CorrectMatchResult` emits it again when a voided match that keeps its time is on again between new sides. A bye is never announced. Consumers treat the event as an upsert keyed on `match_id`. |
| `MatchFinished`, `MatchResultCorrected` | Not in §12 (ADR-0012, D-CMP-12) | Add `brand_id` | Unchanged. |

**Consumed.** §12 lists four Admission events and `UserProfileUpdated`. The
code consumes six Admission events (§6 lists them and says why),
`UserRegistered` beside `UserProfileUpdated`, Registration's
`RegistrationSubmitted` and `RegistrationConfirmed` (D-CMP-05's read model,
since slice 10), Event's `EventCreated`, `EventDetailsUpdated`,
`EventRescheduled` and its seven lifecycle events (§10's entries above), and
Identity's four linked-account events (§5.6). The code is right. Each addition
is recorded where it was made.

**Messaging.** §12 names four notices. The code sends three: the fill-up
(`competition.roster-filled`), the decline (`competition.entry-declined`) and
"your opponent reported a result" (`competition.result-reported`). The two it
does not send are "a match was disputed" and "checked in". The desk's half of
the first is covered by its own screen and the new
`KonventixCompetitionDisputeWaiting` alert (§8.4's entry above). At the event,
the sides see the dispute on the match page and as *Streitfall offen* on the
*Nächste Spiele* card (§10a), and a check-in shows on the *Meine
Competitions* card. *(Superseded in the fix round: both notices are built;
see "The two §12 notices" below.)*

**Naming.** §7.3's `SignupSeedSource` does not exist under that name. Its
job is split in two. `SignupField` decides who is in the field and in which
stable order (§6, §7.5). `RandomSeedSource` is the source registered for
`entry_source = signup`: it orders that field at random, which is §7.5's
default. `ManualSeedSource` carries the desk's drag order and is an explicit
override, so it is never registered. `PreviousStageSeedSource` keeps its
name.

§7.5's `ResetStage` exists as written: it deletes the stage's matches, slots
and edges and clears `generated_at`. `RegenerateBracket` is **not** a rename
of it. It is `ResetStage` followed by `GenerateBracket` in one transaction,
the desk's "draw again". It is the only way to redraw in `Running`, where a
bare reset would leave a running competition with no bracket (see "where in
the lifecycle the desk may draw" above). Both exist, and both refuse once
the first result is recorded (D-CMP-20).

### §7.3, 2026-09-23 — what "the previous stage is done" means is not decided

Two questions have one answer that nobody has given yet. Both must be
answered before `PreviousStageSeedSource` is written, not inside it.

- **When is a stage complete?** No `Stage::isComplete()` exists, and §7.3
  never says what it would mean: every match finished, the final round
  finished, or the organizer declaring it. Nothing today stops `SeedStage`
  from running against a half-played predecessor and advancing whoever is
  on top at that moment.
- **What happens at a tie on the cut?** Standings share placements
  (standard competition ranking, `SPEC.md`), so "the best eight" of a field
  where four share 7th place is not eight entrants. The standings row holds
  what a rule would need (`points`, `tiebreaker`, `seed`). Which rule applies
  (a playoff, a count-back on the tiebreaker, the seed, or a coin toss) is a
  product decision.

Neither can happen in 0.9.0. The UI builds one stage per competition,
`SeedSourceRegistry` refuses `previous_stage`, and a test pins the refusal.
Cross-group ranking of runners-up is a third, related gap, but §7.3 already
lists it as not built.

### §7.5, 2026-09-23 — `entrants.seed` belongs to the competition, seeding to the stage

`entrants.seed` is one column on the entrant and has no stage in it.
`SeedStage` guards on its own stage's results, and then clears and rewrites
`seed` for the whole competition. In a two-stage competition, seeding stage
2 would null the seeds of everyone who did not advance and renumber those who
did. Those are the seeds stage 1's standings use to order tied rows, so a
later recompute of stage 1 (and a §8.5 correction recomputes) would lay out
stage 1's table differently from the same results.

**It is harmless in 0.9.0, and here is why.** A competition has one stage,
so the stage that `SeedStage` guards on is the only one there is.
`SeedStage` refuses once that stage has a result (a finished match, a winner,
a score or a verified score entry), so its seeds never move under a result
they break ties in. And a seed never decides a placement. Tied entrants share
a placement, and the seed only orders the tied rows for display (`SPEC.md`,
"Tied placements"). The second stage must bring seeds per stage (a column
on a stage-entrant row, or seeds kept in the stage's settings) before
`PreviousStageSeedSource` exists.

### §13, 2026-09-23 — erasure forgets the author, not the words

`CompetitionGdprHandler` nulls the person's ULID on every row that must
survive: score entries, result reports, match history, door presence and the
eligibility read model. It deletes the person's profile, interest rows and
linked accounts. What it does not touch is free text on the rows that stay:
a dispute reason, a resolution note, a correction note, a score entry's note
or a rejection note. Once the author is nulled that text is competition
history that nobody can attribute. The exception is text that names its own
author ("Tom hier, wir haben 2:0 gewonnen"). That residual is accepted for
0.9.0. Removing it would take a scrub pass over free text, which is a
different tool from nulling a column.

### §9, 2026-09-23 — the console honours the module gate; its navigation does not

§9 says every screen "honours the module gate". The console runs on the
operator host, which resolves no brand, so `ensure-module:competition`
cannot be route middleware there. `ScopesToOperator` now reads a brand with
`competition` switched off as a stranger's: its rows answer 404 on every
console route, the lists leave it out, and the brand pickers do not offer
it. Agenda's editor makes the same check (`isActiveForBrand()`).

The two navigation entries (*Competitions*, *Disciplines*) stay visible to
anyone holding the capability. Platform's admin navigation deliberately has
no brand gate (ADR-0029), and one operator console serves several brands, so
there is no brand to ask. Hiding an entry when no brand of the operator runs
the module would be a Platform change that affects every module's console
navigation. It was not made here. With the module off for every brand, the
entries lead to empty lists.

### §11, 2026-09-23 — `ViewEntrantContacts` is declared and gates nothing

The capability exists, and Tournament Admin holds it. Tournament Desk does
not. Nothing checks it yet, because Competition holds no contact data. An
e-mail address or a phone number belongs to Identity, which this module may
not read (ADR-0004), and neither `ParticipantProfile` nor any event it is
projected from carries one. It is declared now so that the default roles are
complete: a default role the operator has edited is never rewritten, so a
capability added later would never reach an existing brand's Tournament
Admin. The contact view on the entrants screen arrives with a read model
that has contacts, and it is owed in `TODO.md`.

### §13, 2026-09-23 — which listeners the feature-off test expects to short-circuit

§13 asks that "every listener short-circuits with `competition` off for the
brand". Read literally, that contradicts decisions already recorded here. The
eligibility projection, the name projection, the event summary and the
linked-account projection are **ungated on purpose**, so that a brand
switching the module on in the middle of an event finds its confirmed
registrations, names, events and linked accounts already there. The
linked-account events carry no brand at all. *(Corrected in Task 7c's fix
round 1: the first version gated the linked-account projection on any brand
running the module, see §5.6.)* `CompetitionActivationTest` holds the code
to the reading that follows from those decisions. Every listener that stores
something for the brand (door presence) stores nothing while the module is
off, and the ungated projections still write. The four metric recorders
listen only to Competition's own events, which cannot occur for a brand that
has the module off.

### §13, 2026-09-23 — the metrics, as built

The four metrics of §13 exist with the names §13 gives them. Each carries a
`brand` label (the slug), like every module metric. The two `_total` metrics
are counters of transitions, not current counts. `competition_entrants_total`
counts entrants moving into `registered`, `incomplete`, `checked_in`,
`withdrawn` or `disqualified`. `competition_matches_total` counts `scheduled`
announcements, including a reschedule, and `finished`, once per match. The
two gauges count what is waiting right now.

§13's alert is "a dispute older than 30 min while the competition is
`Running`". It is built as `competition_disputes_open > 0 for 30m`, per
brand, where the gauge counts only disputes in running competitions. The
difference is that a brand that always has *some* dispute open, each one
resolved within minutes, also fires. The runbook
(`docs/operations.md#konventixcompetitiondisputewaiting`) says so. A desk that
is never idle is worth a look too, and an age gauge would be a fifth metric
§13 does not name.

### §8.3, 2026-09-23 — a leaderboard needs no draw to start

Found by the definition-of-done flow. §4 makes `Running` require a
generated stage, and `StartCompetition` read that as `generated_at` being
set. Nothing sets it for a leaderboard stage: `GenerateBracket` has no
generator for the format (a leaderboard has no matches, §7.2), and the desk
offers no draw button for one. A leaderboard competition could therefore
never reach `Running`, and so never `Finished`. Its scores could still be
entered in `SignupClosed`, because the lifecycle guard allows that, which is
why nothing noticed. `StartCompetition` now counts a leaderboard stage as
ready without a draw. Its field is whoever signed up.

### ADR-0001, 2026-09-23 — the participant's refusals are German

Every refusal in `CompetitionEntryException` and `ResultSubmissionException`
keeps its English message, which is for the console, the log and the tests.
Each cause a participant can reach also carries a German sentence
(`participantMessage()`), and the participant controllers show that one. The
sentence names no ULID, no design section and no code value. A cause only the
console reaches has no German sentence and answers a participant with a
generic German one, so a participant never reads English even if such a
cause reaches them later. The join form stays opaque: an unknown invite code
answers with `joinRefusal()`, the same single sentence `TeamJoinController`
gives for every other join refusal. The proof gate's refusals, the upload's
image check, the dispute and verified-score refusals and the reason a
counter-report writes into the match history are German as well. The desk's
own heat refusal stays English.

### §12, 2026-09-23 (Task 8 fix round) — `MatchScheduled` has no retraction yet

`MatchScheduled` is an upsert keyed on `match_id` (see "§12 reconciled").
There is no event that takes an announcement back, and three paths need one.
GameOps 0.10 must add it before it provisions on `MatchScheduled`:

1. **A redraw deletes announced matches silently.** `RegenerateBracket` is
   allowed in `Running` until the first result, and `ResetStage` in
   `SignupClosed`. Both refuse after a *result*, not after a *schedule*, so
   timed and announced matches disappear without an event.
2. **A correction can empty a slot of an announced, unplayed match.**
   `CorrectMatchResult::reroute()` clears a downstream slot whose feeder no
   longer has a winner. The match keeps its time, and its last announcement
   still names the side that is gone.
3. **A voided match is announced again and finishes without a second
   `MatchFinished`.** `CorrectMatchResult::void()` re-announces a timed
   voided match with its new sides. When it is played again,
   `finished_event_emitted_at` keeps D-CMP-12 true, so GameOps never hears the
   replay end and gets no teardown for a second deployment.

The controller ruled on 2026-09-23 that 0.9.0 documents this and does not
build `MatchCancelled`. Nothing consumes `MatchScheduled` before GameOps 0.10,
and the 0.10 brainstorm already decided to add `MatchCancelled` (#179), so
the contract's shape belongs there. Whatever it takes, it must cover the three
paths above. For (3) it must also say how a replay's deployment ends: either
`MatchCancelled` before the re-announcement, or `MatchResultCorrected` plus
the later result as the end.

### §12, 2026-09-23 (Task 8 fix round) — the two §12 notices

The two notices the reconciliation deferred are built, one template and one
call each, and both are sent after the commit. They are skipped for a brand
that has `competition` off, and a failed send never fails the Action:

- `competition.match-disputed` goes from `DisputeMatchResult`, on a direct
  dispute and on a contradicting counter-report alike. It goes to every
  roster member of the other side, whose claim or result was contested.
  §12 names the desk as the reader of "the desk is notified", but Competition
  keeps no list of who is on the desk (§8.4's entry above). So the desk keeps
  its screen and the alert, and the notice goes to the side that did not
  raise the dispute.
- `competition.checked-in` goes from `CheckInEntrant` to the roster. When the
  captain checked the team in themselves, it goes to everybody except the
  captain. When the desk did it, it goes to everybody. A second click, which
  is a no-op, sends nothing.

### §8.3, 2026-09-23 (Task 8 fix round) — the desk owes no proof

A `required` proof policy is what participants owe. The desk has no upload
control (#322), and the participant pages already say the desk collects the
result and the proof (§10's entry above). Before this fix, the desk could not
enter a score for a `required` competition at all: `SubmitScoreEntry` asked
`ProofGate` on the desk path too. The organizer path is now exempt from the
demand. A proof the desk does send is still checked, and the gate's
refusals are English on the desk and German to a participant (ADR-0001).
`RecordResultAtDesk` and `ResolveDispute` never asked for a proof.

`StartCompetition` now reads the **first** stage: it has to be drawn or be a
leaderboard. A later leaderboard stage behind an undrawn bracket no longer
lets a competition start ("A leaderboard needs no draw to start").

### §5.5, 2026-09-23 (final review fix wave A) — a disqualification in front of an opponent still to come

§5.5 says a disqualification forfeits the entrant's pending matches, "each
recorded as a forfeit result through `RecordMatchResult`, so the bracket
advances the normal way". It is silent on a pending match whose other side
has not arrived yet, which is the common case: a team that won round 1 is
disqualified while its round-2 opponent is still playing. The first build
finished that match with no winner. The opponent, once it arrived, landed in
a match that was already over and never advanced, and the next opponent
downstream was handed a walkover by the bye rule.

`DisqualifyEntrant` now tells three shapes apart:

- **The opponent is there.** A 1–0 forfeit, as §5.5 says.
- **The opponent is still to come.** Nothing is recorded. The disqualified
  entrant's slot is emptied, and the match stays open. Its feeder is
  finished, so when the opponent arrives the empty slot reads as a **bye**,
  and the desk settles it the way it settles every bye: by giving the lone
  side the match. This is the conservative reading. Settling the bye
  automatically when the opponent arrives would change what `AdvanceBracket`
  does for every bye, and nothing else in the module finishes a match
  without somebody deciding it.
- **Nobody is coming** (the other slot is a bye). The match is finished with
  no winner, so the slot it feeds becomes a bye for whoever arrives there.

The pass loops until no pending, scheduled or live match still holds the
entrant, because in double elimination a forfeit routes the disqualified
entrant on as the loser, into the losers' bracket, where the next pass takes
it out again. Afterwards every drawn stage (and a leaderboard) is
recomputed once: the forfeits move wins and points, and the disqualified
entrant leaves the table.

Emptying the slot writes no history row. §8.6 has no kind for it, and the
disqualification itself is on the entrant.

### §4, 2026-09-23 (final review fix wave A) — the bracket is started on the field it was drawn from

§4 has the bracket drawn in `SignupClosed` and started with `start`. Several
legal moves change the field in between: a withdrawal (a captain's is allowed
until `Running`), a declined incomplete roster, a roster change that makes an
entrant incomplete, and, where the competition checks in, a late check-in or
an undone one. None of them touched the bracket, and `start` asked only
whether a stage was drawn. The withdrawn team kept its slots; the late
arrival was never drawn.

Two rules close it:

- **`ReopenSignup` refuses while any stage is drawn.** A reopen goes back to
  `Published`, where signup adds entries a bracket would never draw — the
  reason the draw is never made there (the §4/§7.5 entry above). The desk
  resets the draw first.
- **`StartCompetition` compares the fields.** `GenerateBracket` keeps the
  field it drew from on the stage (`competition_stages.drawn_entrant_ids`),
  and `start` refuses when the first stage's drawn field and
  `SignupField::entrantIds()` differ as sets, naming how many entrants are
  missing on each side. The desk draws again, which is free before the first
  result. The field is kept rather than read back from the slots because an
  odd Swiss round one leaves its bye entrant out of every match. A
  **disqualified** entrant does not count as a difference: its matches were
  forfeited, which is how the bracket already deals with it, and a forfeit is
  a recorded result that would forbid the redraw. A leaderboard, drawn from
  nobody, is not compared.

The migration fills the column for stages drawn before it from their slots.
An odd Swiss round one drawn before the migration and not yet started is
therefore refused at `start` once, and drawn again.

What is not closed: results recorded before `start` (play is allowed in any
non-terminal state) followed by a withdrawal leave a competition that can
neither redraw nor start, and only cancelling ends it. A desk that records
results before the start has to disqualify a leaving team, not withdraw it.

### §8.4, 2026-09-23 (final review fix wave A) — a dispute ends when its match is settled

§8.4 has the desk resolve a dispute with `ResolveDispute` and a mandatory
note. A disputed match can also be settled by other doors: the sides agreeing
after all (a confirm, or a re-report that now matches the other claim), or a
desk correction of a contested result. Each of those finished the match and
left `dispute_resolved_at` null, so the match stayed "disputed" on the desk's
panel, in `competition_disputes_open` and in the thirty-minute alert.

Every settlement now closes the dispute (`Concerns\ClosesADispute`, called
from `SettlesAMatch::settleMatch()` and `CorrectMatchResult`): the timestamp,
a `resolved` history row and `MatchDisputeResolved` after the commit. The
row's note is the correction note where the desk gave one, and otherwise a
German sentence the sides read on the match page. `MatchDisputeResolved.userId`
is therefore whoever ended the dispute — the organizer, or the member of a side
whose agreement settled the match — rather than always an organizer.

A re-report that still disagrees on a match that is already disputed replaces
the side's claim and writes its `reported` row, and the one open dispute stays
where it is. It used to be refused as "already disputed", which threw the
side's edit away.

### §7.2, 2026-09-23 (final review fix wave A) — an entrant with no score has no value on the board

`LeaderboardStandingsCalculator` stored `0.0` as the tiebreaker of an entrant
with no verified entry, because `competition_standings.tiebreaker` was not
nullable. The public board prints `tiebreaker ?? '—'`, so it showed "0 s",
which on a `lower` board reads as the best time in the field beside a last
place. A new migration makes the column nullable, and a leaderboard writes
null for an entrant without an aggregate. Bracket formats always write a
number.

A leaderboard's standings now rank `SignupField`'s field: where the
competition checks in, the checked-in only. Every registered entrant used to
be ranked, so the no-shows of a check-in competition sat on the public board
at a shared last place. Bracket standings keep the wider field, because their
calculators rank from matches that were played.

### §8.5, 2026-09-23 (final review fix wave A) — a correction also takes the claims of a re-paired match

§8.5 voids every downstream match that has a recorded result on a changed
slot. A downstream match that was **reported but not confirmed**, or
disputed but not resolved, has no recorded result, so the cascade skipped it
and its claims stayed after `reroute()` put a different team in the slot. The
side now standing opposite could confirm the displaced team's claim, which
recorded a score for a pairing that never played; a report by the new side
was compared with the stale one and became a false dispute; and a dispute
about a team no longer in the match stayed on the desk's panel.

The cascade now drops the claims of such a match — its reports,
`reported_at` and its dispute — whenever one of its slots changes. It writes
no history row: §8.6 has no kind for it, and the `reported` rows already on
the timeline stay as the record of what was said. `ConfirmMatchResult` and
`ReportMatchResult` also read only claims by an entrant who stands in the
match now.

### §13, 2026-09-23 (final review fix wave A) — erasing a captain hands the team on

The §13 entry "erasure forgets the author, not the words" keeps team
membership as public record, so an erased captain's membership row stays.
Before this fix the **captain role** stayed with it, on an account nobody
could sign in to any more. The team could then no longer check itself in,
withdraw or get a new invite code for the rest of the event, because all
three are captain-only.

Controller ruling (final review, part 4, I3): erasure hands the captaincy to
the longest-standing remaining member, which is `MaintainsTeamRoster`'s rule
for a captain who leaves (`created_at`, then `id`). The erased person's row
stays as a plain member. A team with nobody else on it keeps no captain, and
the desk steps in. `CompetitionGdprHandler::delete()` does this for every
team the person captains, whatever the state of its competitions.

### §4, 2026-09-23 (final review fix wave B) — the signup clock

§4 promised two things a clock does: `SignupClosed` "because
`signup_closes_at` passed and the scheduler closed it", and `SignupOpened`
"when a `Published` competition's window opens (scheduler tick)". Task 1's
review ruled the tick be built. Nothing built it until this wave.

`competition:signup-tick` runs every minute with `withoutOverlapping()`
(`CompetitionServiceProvider::registerSchedule()`). For every `Published`
competition of a brand with the module on (ADR-0003, asked per brand because
the scheduler has none), it:

- runs `CloseSignup` once `signup_closes_at <= now`, the edge
  `signupIsOpen()` already used;
- runs the new `AnnounceSignupOpened` when signup is open and this window was
  not announced yet. Opening writes no state, so the Action records the
  announcement in the new `competitions.signup_opened_at`. A window counts
  as announced when that stamp is not earlier than `signup_opens_at`. So
  moving the opening later re-arms it. `PublishCompetition` and
  `ReopenSignup` stamp it when they emit the same event, and
  `UnpublishCompetition` clears it.

Closing runs before announcing, so a window that opened and closed between
two ticks is never announced as open. A reopen over a passed close date lasts
until the next tick. The organizer moves the date on the same screen, and
`docs/operations.md` says so.

### §5.1, 2026-09-23 (final review fix wave B) — the gate hears a refund, and has a backfill

The eligibility projection listened to `RegistrationSubmitted` and
`RegistrationConfirmed` only, so `is_confirmed` never went back to false. A
guest whose ticket was cancelled or refunded (the refund console cancels
since 0.5.0) or whose registration was rejected could still enter, join,
register interest, be filled in by the desk and be found by the person
search (final review, part 2, I1).

It now also listens to `RegistrationCancelled` and `RegistrationRejected`.
Neither carries a brand or an event, so the row is found by
`registration_id` alone, outside the brand scope, which is what Resources
does. The row keeps its registrant and loses its confirmation. A person with
a second confirmed registration for the event stays eligible through it. An
entrant already in a competition stays entered: the gate is asked at the
door of each act, and taking a team out is the desk's call (§5.5).

A registration written without its events is invisible to the projection:
the hand-run Eventula migration writes rows directly, and rows older than
the projection were never seen. Registration now has
`registration:announce-standing`, which emits the narrow, non-auditable
`RegistrationStandingAnnounced` (registration, event, brand, registrant,
and whether its status is `confirmed` now). It follows the pattern of
`identity:announce-social-identities`. The projection upserts from it, so a
replay brings every row to the registration's current state.
`docs/operations.md` lists it among the Competition backfills.

### §10, 2026-09-23 (final review fix wave B) — a participant write asks the page's rule itself

The fix round made a draft event's tournament pages 404. The participant
writes do not go through `/events/{slug}`: they are keyed by a competition's
or an entrant's id and checked only the competition's status. A published
competition of a draft event therefore took entries, reports, interest and
proofs by id (final review, part 4, M10).

Every participant write now asks what the pages ask: the competition is not
a draft, and its event has a projected name and a public state
(`EventSummary::isPublicEvent()`). That covers enter, score entry, report,
confirm, dispute, interest, proof upload, and a captain's withdrawal and
check-in. Anything else is a 404, like the page. Team-level writes (create,
join, leave, a new invite code) name no competition and keep the §5.1 gate
as their only event check.

### §8.2/§10a, 2026-09-23 (final review fix wave B) — the readers ask the Actions' question about claims

Wave A made `ConfirmMatchResult` and `ReportMatchResult` read only claims by
an entrant who stands in the match now. The match page's buttons, the hub
card's nudge and *Meine Turniere*'s "Ergebnisse zum Bestätigen" still read
every claim on the match. So a claim left by a side that was moved out of
its slot could still be offered for confirmation, and the Action then
refused it. All three now read through `MatchPowers::currentClaims()` (the
list: a correlated check on `match_slots`), which is the Actions' rule.

### ADR-0001, 2026-09-23 (final review fix wave B) — one word per thing on the participant side

The participant pages said *Einspruch* where the hub card said *Streitfall
offen*, and *Orga* where the refusals and the dispute mail said
*Turnierleitung* (final review, part 3, M6). The controller's ruling: use
*Einspruch* if §8's German says so, and *Streitfall* otherwise. §8 has no
German, and the only German this design uses for the state is §10a's
*Streitfall offen*. So a disputed match is a **Streitfall** everywhere, and
the act of raising one is **Ergebnis anfechten**. The people who decide are
the **Turnierleitung** everywhere.

A leaderboard stage saved without a unit now gets *Punkte*, not *pts*.
Stages saved earlier keep the *pts* they stored. The public standings print
the stage's `decimals` with a German decimal comma (`formatScore()`).


### §7.1/§8.2, 2026-09-23 (fix wave A, round 2) — a match nobody can reach is closed

An edge can deliver nobody: a bye has no loser, and a match that a
disqualification finished has no winner. A match whose every feeder has
finished without delivering anybody has no side, and nothing can ever give it
one. The desk cannot record a match against nobody (`guardEverySideHasArrived`
refuses an empty one), so it stayed open for ever, and the match it feeds
waited on it for ever. Three shapes reached it: a disqualification in double
elimination in front of an unsettled bye; two disqualifications on converging
paths in single elimination; and **double elimination with five entrants and
no disqualification at all**, where two winners'-bracket byes feed the same
losers'-bracket round-1 match.

`AdvanceBracket` now asks every match an advanced match feeds: open, no side,
every feeder finished? Then it is finished with no winner, a `recorded`
history row says why in German, and it advances in turn, so "nobody" travels
on and the next match sees an empty slot behind a finished feeder — the bye
rule. It runs after every settlement, so no generation-time step is needed:
the desk settles the byes anyway, and the last one to settle closes the empty
match behind them. No `MatchFinished` goes out for it: nothing was played and
nothing was announced.
