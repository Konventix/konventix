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

---

## 6. Check-in (D-CMP-18)

Two knobs on the competition:

| Column | Values | Meaning |
|---|---|---|
| `check_in_mode` | `none` / `self_service` / `desk` | `none`: every registered entrant counts as ready. `self_service`: the captain checks the team in on the participant page during a window of `check_in_opens_minutes_before` minutes before `starts_at`, closing at `start`. `desk`: only the desk checks teams in (`CheckInEntrants`). The desk can always check in or undo, whatever the mode. |
| `requires_event_check_in` | bool | If true, a team can only be checked in when **every roster member** has been seen at the event door. |

The door fact comes from a new read model `competition_admission_presence`
(`brand_id`, `event_id`, `admission_id`, `holder_user_id` nullable,
`checked_in_at` nullable), projected from `AdmissionHolderAssigned` /
`AdmissionHolderUnassigned` (who holds it) and `AdmissionCheckedIn` (that
it was scanned, direction `in`), and cleared by `AdmissionRevoked`. Both
listeners are idempotent and replayable (ADR-0004); this is the same shape
Resources built for #292. The check-in Action reads only this table.

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

### §6, 2026-09-23 — door presence also listens to `AdmissionIssued`

§6 names `AdmissionHolderAssigned` / `AdmissionHolderUnassigned` as the
source of *who holds* an admission. That is incomplete:
`AdmissionHolderAssigned` is emitted when an initially **unassigned**
admission is claimed (ADR-0032), while the ordinary case — somebody buys a
ticket for themselves — names the holder on `AdmissionIssued` and emits no
second event. Listening to §6's list alone would leave `holder_user_id` null
for most people at the door and every `requires_event_check_in` competition
would refuse its whole field.

`Listeners\ProjectAdmissionPresence` therefore also consumes `AdmissionIssued`
and `AdmissionOwnershipAnnounced` (#292's backfill, so admissions issued
before this read model existed appear in it). Both are strictly additive, both
are public events, and Resources' `ProjectAdmission` consumes the same set for
the same reason.

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
