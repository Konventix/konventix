# Claimed-Ticket Eligibility Implementation Plan (0.9.1, #330)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A person who holds a ticket in their own name for the event can create a team, enter a competition, join a team and put their name on the interest list, even without a registration of their own. Today that is someone who claimed a ticket from another person's group booking. When the ticket is taken off them or revoked, the gate closes again, unless they also have a confirmed registration of their own.

**Architecture:** There is no new table and no new listener. Competition already projects "who holds which admission that still stands" into `competition_admission_presence`: `ProjectAdmissionPresence` names the holder on `AdmissionIssued` / `AdmissionHolderAssigned` / `AdmissionOwnershipAnnounced`, clears it on `AdmissionHolderUnassigned`, and deletes the row on `AdmissionRevoked` or when an announcement says `revoked`. The §5.1 gate, `EligibleRegistration::userMayEnter()`, grows a second leg that asks that table. The console's person search, `ParticipantProfile::searchEligible()`, grows the same leg. Every call site already goes through those two methods, so no Action changes. The backfill is the existing `admission:announce-ownership`.

**Tech Stack:** Laravel 12 module `app-modules/competition`, Pest on Postgres, Inertia v3 + Vue 3 (one line of copy).

**Spec:** GitLab issue #330; `docs/superpowers/specs/2026-09-18-competition-design.md` §5.1 (the gate, D-CMP-05), §6 (door presence and its six source events), §14 (the two §6 entries on the presence listeners and their module gate); and the operator's decision of 2026-09-23: *eligibility = own confirmed registration OR an assigned, non-revoked admission for the event*. Task 1 writes that decision into §5.1. From then on the addendum is the binding text.

## Global Constraints

- Work happens in the MAIN checkout `/home/mawiguko/git/konventix/konventix-poc` on `develop` (no worktree).
- **No local test runs** (operator instruction). Write the tests, and the GitLab pipeline runs them. Run `vendor/bin/sail bin pint --dirty` and `vendor/bin/sail composer types:check` (phpstan) locally before every commit. Task 3 changes a `.vue` file, so it also runs `vendor/bin/sail npm run lint`, `format`, `types:check` and `build`.
- Expected values in tests are derived by hand, with the derivation in a comment.
- The format-on-edit hook strips unused imports. Add each `use` in the same edit as its first use.
- ULIDs (ADR-0014). `brand_id` is never mass-assigned. Events are `final readonly`, carry ULIDs and scalars only (ADR-0004), and are dispatched through `DB::afterCommit()` when raised inside a transaction. This plan adds no event. Datetime parameters are typed `?CarbonInterface` (#312). Participant copy is German and console copy is English (ADR-0001). Listeners are registered explicitly and gated per ADR-0003; this plan adds none and changes no registration. Inertia tests call `$this->app->forgetScopedInstances()` between two requests in one test.
- **Competition may not read Admission's tables** (ADR-0004). The ticket leg reads only `competition_admission_presence`, Competition's own projection, and no `Konventix\Admission\Models\*` import appears anywhere.
- Test helpers are process-global under Pest. Prefix every helper declared in a test file (`claimedTicketTest…`), per `.ai/rules/tests.md`.
- Commit messages: one plain sentence in the repo's style, ending with:
  `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`
  `Claude-Session: https://claude.ai/code/session_01QPCmPxfGD9UDAAUGKgwEbN`
- New post-rollout backfills are listed in `docs/operations.md`'s main backfill list. This plan adds none. It gives an existing one a second reason (Task 3).
- Read before touching matching paths: `.ai/rules/{models,src,tests,js}.md`.

## What already exists (verified 2026-09-23 — do not rebuild)

- **`competition_admission_presence`** (migration `2026_09_23_020000_create_competition_admission_presence_table.php`). Columns: `admission_id` (PK, the admission's own ULID), `brand_id`, `event_id`, `holder_user_id` nullable, `checked_in_at` nullable. It has the index `competition_presence_holder_idx` on `(brand_id, event_id, holder_user_id)`, which is exactly the lookup the new leg makes. Model: `Konventix\Competition\Models\AdmissionPresence`, with `seenAtTheDoor()` and `missingAtTheDoor()`.
- **`Listeners\ProjectAdmissionPresence`**, registered in `CompetitionServiceProvider::registerListeners()`:
  - `handleIssued(AdmissionIssued)` upserts `event_id` and `holder_user_id`. The holder is non-null only for a crew badge (`IssueCrewAdmission`).
  - `handleHolderAssigned(AdmissionHolderAssigned)` upserts `holder_user_id`. This is the claim path (`ClaimAdmission`, `AssignHolder`). A second assignment overwrites the holder.
  - `handleHolderUnassigned(AdmissionHolderUnassigned)` sets `holder_user_id` to null and keeps the row.
  - `handleRevoked(AdmissionRevoked)` **deletes** the row.
  - `handleOwnershipAnnounced(AdmissionOwnershipAnnounced)` deletes the row when `state === 'revoked'` and otherwise upserts the holder. This is the backfill.
  - `handleCheckedIn(AdmissionCheckedIn)` fills `checked_in_at`. It is irrelevant here.
  - **Every handler short-circuits when `competition` is off for the event's brand** (`ModuleGate::isActiveForBrand('competition', …)`, #298).

  So a row whose `holder_user_id` is set is exactly an "assigned, non-revoked admission" as far as Competition knows. No new projection is needed.
- **Admission's payloads (verified in `app-modules/admission/src/Events/`):**
  - `AdmissionHolderAssigned(admissionId, registrationId, eventId, brandId, holderUserId, viaClaimLink, assignedAt)`
  - `AdmissionHolderUnassigned(admissionId, ?registrationId, eventId, brandId, previousHolderUserId, ?unassignedByUserId, reasonCode, unassignedAt)`
  - `AdmissionRevoked(admissionId, ?registrationId, eventId, brandId, ?revokedByUserId, reasonCode, revokedAt)`, emitted by `RevokeAdmissionsForRegistration` and `RevokeUnitsForRegistration`
  - `AdmissionOwnershipAnnounced(admissionId, ?registrationId, eventId, brandId, ?holderUserId, ?buyerUserId, state)`
  - `AdmissionIssued(admissionId, ?registrationId, eventId, brandId, role, ?holderUserId, issuedAt, ?buyerUserId)`

  `AdmissionState` is `issued | assigned | revoked | expired`. `expired` has no producing Action.
- **The registration leg:** `EligibleRegistration::userMayEnter(string $brandId, string $eventId, string $userId): bool` checks `competition_eligible_registrations` with `is_confirmed = true`. It is fed by `ProjectEligibilityOnRegistrationEvents`: submitted, confirmed, `withdrawn()` on `RegistrationCancelled`/`RegistrationRejected`, and `announced()` on `RegistrationStandingAnnounced` for `registration:announce-standing`. It is ungated.
- **Every gate call site goes through `userMayEnter()`:** `EnterCompetition::handle()` (per entering member), `CreateTeam`, `ExpressInterest`, `MaintainsTeamRoster::addRosterMember()` (join by code, desk add, fill-up, move), `CompetitionPageController` (the German pre-check before entering), and `TournamentPages::competitionViewer()` (`CompetitionViewerData::$eligible`). The one exception is **`ParticipantProfile::searchEligible()`**, which inlines an `EligibleRegistration` subquery. It feeds `EntrantAdminController`'s person search.
- **Refusal copy:** `CompetitionEntryException::noConfirmedRegistration()` and the controller's sentence at `CompetitionPageController.php:72`. Both are pinned by tests: `ParticipantCopyTest.php:157` and `PublicPagesTest.php:789`. The participant panel's line is in `resources/js/components/public/EntryPanel.vue:116`. The console's empty-search line is in `resources/js/components/admin/TeamRosterEditor.vue:190`.
- **GDPR:** `CompetitionGdprHandler` already nulls `competition_admission_presence.holder_user_id` for an erased person. An erased person therefore drops out of the ticket leg with no change.
- **Backfill:** `admission:announce-ownership` (`AnnounceAdmissionOwnershipCommand`) is already in `docs/operations.md`'s main list and in *Turning tournaments on* ("Run it after enabling the module").
- **Precedent for "not retroactive":** §5.6 *Not retroactive* (Task 7c's linked-account rule). Losing eligibility later removes nobody from a roster. §5.1's cancelled-registration handling (final review part 2, I1) behaves the same: `EligibilityWithdrawalTest` refuses the *next* write and leaves standing rosters alone.

---

### Task 1: The §5.1 addendum

**Requirements:** the operator's decision of 2026-09-23 and issue #330's *Umfang*. The addendum is written **before** any code, so that Tasks 2 and 3 cite it rather than the issue.

**Files:**
- `docs/superpowers/specs/2026-09-18-competition-design.md`: a dated addendum directly under §5.1's paragraph, and one row in §3 *Decisions at a glance*.
- `docs/inventory/decision-log.md`: the D-CMP-05 row gets an "(amended 2026-09-23, #330: …)" clause in its *Chosen* cell. Leave the original text standing.

**What the addendum states (all of it, in prose, headed `#### Addendum, 2026-09-23 — a ticket in your name also opens the gate (#330)`):**

1. **The rule.** A person passes the gate when Competition's read models hold, for the competition's brand and event, **either** a confirmed registration of theirs (`competition_eligible_registrations`, unchanged) **or** an admission whose holder they are that has not been revoked (`competition_admission_presence` with `holder_user_id` = them). "Nothing else — admission is §6's business" is superseded for the second leg only. Possession is still not *checked at the door* at signup. That remains §6's `requires_event_check_in`.
2. **Why.** Someone who claimed a ticket from another person's group booking has no registration of their own and could not sign up for anything.
3. **Where the fact comes from.** The ticket leg reads §6's presence projection and adds no new one. That projection already names the holder on `AdmissionIssued` (crew badge), `AdmissionHolderAssigned` (claim or organizer assignment) and `AdmissionOwnershipAnnounced` (backfill). It clears the holder on `AdmissionHolderUnassigned` and deletes the row on `AdmissionRevoked` or an announced `revoked`. No table of Admission's is read (ADR-0004).
4. **Losing it.** When the holder is removed or the ticket is revoked, the person loses eligibility **unless they have their own confirmed registration**. A revoked registration-derived ticket usually means the registration was cancelled, and then both legs go together. **Existing rosters, entries and interest rows are not changed retroactively.** The next enter, join, create-team or interest write is refused, exactly as §5.6 *Not retroactive* and the cancelled-registration handling do. The desk deals with a player who should no longer be on a roster (withdraw, remove member).
5. **Crew badges count.** An admission is an admission. A crew member holding a badge for the event passes the gate. The operator's rule names no role, and `AdmissionOwnershipAnnounced` carries none, so a role filter could not be backfilled anyway.
6. **The gate asymmetry, stated honestly.** The registration leg is ungated. The ticket leg inherits the presence listeners' module gate (§14, *what the module gate on door presence costs*). A brand that switches `competition` on finds its registrants eligible at once, but its ticket holders only after `admission:announce-ownership` has run. The same applies when a brand switches the module off and on again: a revocation or unassignment heard while it was off never reached the table. Run the command **after every enable**, not only the first. It is idempotent, and an announced `revoked` deletes the stale row.
7. **`expired`.** `AdmissionState::Expired` has no producing Action today. An announced `expired` admission keeps its holder, and therefore keeps the gate open. Whoever builds expiry owes this leg a listen.
8. **Backfill.** `admission:announce-ownership` covers tickets claimed before this deploy. No new command.

The §3 row reads: `D-CMP-05 (amended 2026-09-23)` | **A confirmed registration or a non-revoked admission held in one's own name opens the signup gate; losing either later removes nobody.** | §5.1.

**The boundary that bites:** the addendum must not claim that the ticket leg is ungated "like the other eligibility projections". It is not, and point 6 exists so that nobody later "fixes" this by ungating `ProjectAdmissionPresence`, which #298's second acceptance criterion forbids.

**Tests:** none. This task is docs only. Commit it alone.

---

### Task 2: The gate's second leg

**Requirements:** the §5.1 addendum from Task 1, points 1, 3, 4, 5 and 6. Issue #330, acceptance criteria 1 and 2.

**Files:**
- `app-modules/competition/src/Models/AdmissionPresence.php`: two new static methods.
- `app-modules/competition/src/Models/EligibleRegistration.php`: `userMayEnter()` ORs in the ticket leg. Rewrite the class docblock's "*D-CMP-05's second half … deliberately not modelled here*" paragraph to cite the addendum.
- `app-modules/competition/src/Models/ParticipantProfile.php`: `searchEligible()` unions the ticket leg.
- Docblocks that assert "a confirmed registration and nothing else" get one clause pointing at the addendum. Do not rewrite whole paragraphs. The files are:
  - `Actions/EnterCompetition.php`: "The entry gate is a confirmed Registration, and nothing else …"
  - `Actions/Concerns/MaintainsTeamRoster.php`: point 1
  - `Actions/CreateTeam.php`
  - `Actions/ExpressInterest.php`
  - `Actions/JoinTeamByCode.php`
  - `Actions/MoveTeamMember.php`
  - `Http/Controllers/InterestController.php`
  - `Http/Controllers/CompetitionPageController.php`: the comment at line 61
  - `Data/CompetitionViewerData.php`: the `$eligible` docblock
  - `Enums/EntrantState.php`
  - `Listeners/ProjectAdmissionPresence.php`: the class docblock gains one sentence saying that the §5.1 gate also reads this table
- `app-modules/competition/SPEC.md`:
  - The `Admission*` event row (line 406): replace "Never a signup gate (D-CMP-05)" with "and, since #330, the ticket leg of the §5.1 gate".
  - The resolved open-question entry at line 526ff: append the amendment.
  - Line 440 ("they follow from a confirmed registration"): add "or a ticket in one's name".
- `app-modules/competition/TODO.md`: a checked item for #330. Add an unchecked *Owed* item: "the ticket leg keeps an `expired` admission's holder, and whoever builds expiry owes it a listen" (addendum point 7).
- `app-modules/competition/tests/ClaimedTicketEligibilityTest.php`: new.

**Interfaces produced:**

```php
// AdmissionPresence
/** Whether this person holds a non-revoked admission for the event (§5.1 addendum). */
public static function holdsAdmission(string $brandId, string $eventId, string $userId): bool;

/** The holders of this event's non-revoked admissions, as a subquery for `whereIn('user_id', …)`. */
public static function holderIdsQuery(string $brandId, string $eventId): \Illuminate\Database\Eloquent\Builder;
```

Both use `self::withoutBrandScope()->where('brand_id', $brandId)->where('event_id', $eventId)` with an explicit brand, for the same reason `seenAtTheDoor()` gives: queue workers and the operator console (ADR-0029). `holdsAdmission` adds `->where('holder_user_id', $userId)->exists()`. `holderIdsQuery` adds `->whereNotNull('holder_user_id')->select('holder_user_id')`. Neither looks at `checked_in_at`: holding is enough, and being scanned is §6's question.

**Interfaces changed (the signature is unchanged):** `EligibleRegistration::userMayEnter($brandId, $eventId, $userId)` returns the existing registration query `|| AdmissionPresence::holdsAdmission($brandId, $eventId, $userId)`. `ParticipantProfile::searchEligible()` wraps its `whereIn('user_id', …)` in a closure: `->where(fn ($q) => $q->whereIn('user_id', <registration subquery>)->orWhereIn('user_id', AdmissionPresence::holderIdsQuery($brandId, $eventId)))`. The closure keeps the `orWhereIn` from escaping into the name/username `where` that follows.

**Interfaces consumed:** Admission's five events listed above, **only in tests**, through `event(new …)`, the same way `CheckInTest` emits them. `$this->activateModule('competition', $brand)` in every test that expects a presence row.

**The boundary that bites:** `userMayEnter()` is called from seven places, and **none of them may change**. If a caller starts asking `AdmissionPresence` itself, the gate has two definitions. The same applies to the person search: it must use `holderIdsQuery()`, not re-state the `where` clauses. The second trap is the module gate. A test that forgets `activateModule` writes no presence row and passes its *refusal* assertions for the wrong reason. Every refusal below is therefore asked of a person who was eligible a moment earlier, in the same test (`EligibilityWithdrawalTest`'s pattern).

**Tests** (`tests/ClaimedTicketEligibilityTest.php`; helpers `claimedTicketTestIssue(...)`, `claimedTicketTestClaim(...)`, `claimedTicketTestRevoke(...)`, `claimedTicketTestUnassign(...)`, which emit the Admission events with fresh ULIDs):

- **`lets the holder of a claimed ticket create a team, enter, join by code and register interest without a registration of their own`**: `AdmissionIssued(holderUserId: null, buyerUserId: $buyer)`, then `userMayEnter` is false. This is the non-vacuous half: an issued, unclaimed ticket names nobody. After `AdmissionHolderAssigned(holderUserId: $holder)`, `userMayEnter` is true. `CreateTeam` succeeds with the holder as captain. `EnterCompetition::handle($competition, $team, [$holder])` returns an entrant. A second holder joins another team through `JoinTeamByCode`. `ExpressInterest` succeeds for a third holder on a second competition. No `RegistrationSubmitted` is emitted for any holder anywhere in the test.
- **`finds a ticket holder in the console's person search`**: `ParticipantProfile::searchEligible($brandId, $eventId, 'Tina')` returns `[$holder]` after the claim. It also returns a registrant with a matching name, so the union keeps the old leg. Expected: `[$registrant, $holder]` ordered by `display_name` ("Tina Holder" < "Tina Registrant", derived alphabetically in the comment).
- **`closes the gate when the holder is removed, and leaves the roster alone`**: the holder enters, then `AdmissionHolderUnassigned` makes `userMayEnter` false. `CreateTeam`, `JoinTeamByCode` and `ExpressInterest` throw `CompetitionEntryException`. Their team's roster still holds them, and the entrant's state is unchanged (addendum point 4, not retroactive).
- **`closes the gate when the ticket is revoked`**: the same shape with `AdmissionRevoked`. The presence row is gone (`AdmissionPresence::withoutBrandScope()->whereKey($admissionId)->exists()` is false).
- **`keeps a holder with their own confirmed registration eligible after the ticket is revoked`**: `competitionEligibleUser($eventId, $brandId, $holder)` plus a claimed ticket, then revoke. `userMayEnter` is still true.
- **`does not open the gate for a ticket at another event or another brand`**: a claimed ticket at event B leaves `userMayEnter($brand, $eventA, $holder)` false. So does a claimed ticket at the same event id under a second brand, because the lookup takes the brand explicitly.
- **`lets a crew badge holder in`**: `AdmissionIssued(role: 'crew', holderUserId: $crew)` alone makes `userMayEnter` true (addendum point 5).
- **`backfills a ticket claimed before the deploy through the ownership announcement`**: `AdmissionOwnershipAnnounced(state: 'assigned', holderUserId: $holder)` alone makes `userMayEnter` true. A second announcement for the same admission with `state: 'revoked'` makes it false. This is #330's acceptance criterion 3 at the listener level.
- **`does not see a ticket claimed while the module was off, until the announcement runs after enabling`**: without `activateModule`, a claim writes no row and `userMayEnter` is false. After `activateModule` and an `AdmissionOwnershipAnnounced` for that admission, it is true (addendum point 6, recorded as behaviour, not as a bug).
- **`marks a holder eligible on the competition page`**: an Inertia GET of the public competition page (`TournamentPages`, the route `PublicPagesTest` uses) as the signed-in holder asserts `viewer.eligible === true` and a non-empty `viewer.enterableTeams` once they captain a team. It covers the one read-only call site the Action tests do not.

Update `CompetitionActivationTest` only if its feature-off assertions start failing. They should not, because no listener changed.

---

### Task 3: Copy and the operator note

**Requirements:** the addendum's points 1, 6 and 8. Issue #330, acceptance criterion 3 at the operations level. **Light Vue: two copy lines, no component logic.**

**Files:**
- `app-modules/competition/src/Exceptions/CompetitionEntryException.php`: in `noConfirmedRegistration()`, the German sentence becomes *"Dafür braucht es eine bestätigte Anmeldung zur Veranstaltung oder ein Ticket auf den eigenen Namen – für dich und für alle im Team."* The English cause becomes "…holds neither a confirmed registration nor an admission in their name for event [%s]; either entitles someone to enter (D-CMP-05, amended by #330)". Keep the method name: renaming it touches five call sites for no behaviour change.
- `app-modules/competition/src/Http/Controllers/CompetitionPageController.php:72` becomes *"Alle im Team brauchen eine bestätigte Anmeldung zur Veranstaltung oder ein Ticket auf ihren Namen, bevor ihr euch anmelden könnt."*
- `app-modules/competition/resources/js/components/public/EntryPanel.vue:116` becomes *"Mitspielen kann, wer eine bestätigte Anmeldung zur Veranstaltung oder ein Ticket auf den eigenen Namen hat."*
- `app-modules/competition/resources/js/components/admin/TeamRosterEditor.vue`: line 190 becomes "Nobody with a confirmed registration or a ticket in their name matches." Update the docblock at line 25 to match.
- `app-modules/competition/tests/ParticipantCopyTest.php:157` and `tests/PublicPagesTest.php:789`: the pinned strings follow.
- `docs/operations.md`:
  - In *Turning tournaments on*, the `admission:announce-ownership` bullet gains: it also fills the ticket half of the §5.1 gate, so until it runs after an enable, a person whose only entitlement is a claimed ticket cannot enter, join, register interest or be found by the desk's search. Run it after **every** enable of `competition`, including a re-enable.
  - In the main post-rollout list (the paragraph beginning "It joins the manual post-rollout backfills"), give `admission:announce-ownership` a parenthetical reason the way `registration:announce-standing` has one: "(door presence, and since #330 the ticket half of the competition gate)". It is not a new command, so no new list entry.
  - In the same bullet, add: run it once after the 0.9.1 deploy that brings #330. It is idempotent, and it is what covers tickets claimed before the deploy. The file keeps no separate per-release list, so do not create one.

**Interfaces consumed:** none new.

**The boundary that bites:** the copy says *Ticket auf den eigenen Namen*, not *Einlass* or *Admission*. The participant never sees the word Admission, and the claim page speaks of putting a ticket "in your name". Keep the two German sentences parallel, so that the page panel, the Action's refusal and the controller's refusal say the same thing three times.

**Tests:** the two pinned-string tests above, which now pin the new sentences. No new test file. Run `vendor/bin/sail npm run lint`, `format`, `types:check` and `build` for the two `.vue` edits.

---

## Ordering and parallelism

1 → 2 → 3. Task 1 is docs only and lands first, so that Task 2's docblocks and SPEC rows can cite "§5.1 addendum, 2026-09-23". Task 3 depends on Task 2 only in the sense that its copy must not promise a gate that does not exist yet. The two could be one commit each in either order after Task 1, but keep 2 before 3.

## Self-review against the spec

| Source | Task |
|---|---|
| Operator decision 2026-09-23 (the OR rule), design §5.1 amended | 1 (text), 2 (code) |
| Holder removed or ticket revoked → no eligibility unless own registration; not retroactive | 1 (point 4), 2 (tests 3–5) |
| §6 presence projection as the source (AdmissionIssued / HolderAssigned / HolderUnassigned / Revoked / OwnershipAnnounced) | 2 (reuses it; no new listener) |
| §14 *what the module gate on door presence costs*, and its consequence for the gate | 1 (point 6), 2 (test 9), 3 (operations) |
| #330 AC1: a holder without a registration can enter or join | 2 (test 1, page test) |
| #330 AC2: removed or revoked → no eligibility | 2 (tests 3, 4) |
| #330 AC3: backfill covers tickets claimed before the deploy | 2 (test 8), 3 (operations) |
| ADR-0004: no foreign tables read | 2 (reads only `competition_admission_presence`) |
| Console person search | 2 (test 2), 3 (copy) |

**Resolved in writing the plan:**
- *New projection or reuse §6's?* Reuse. `competition_admission_presence` is already exactly "non-revoked admission, holder = person, per event". A second copy fed by the same six events would be a duplicate projection with a second chance to drift. The price is that the ticket leg is module-gated where the registration leg is not. The addendum records this (point 6) and the operations doc gives the recovery, which is already documented for §6.
- *Crew badges?* Included, on the operator's wording. See addendum point 5.

**Not in this plan:**
- Any retroactive removal from rosters, entries or interest lists when eligibility is lost.
- A role filter on the ticket leg.
- Handling `expired` admissions. It is recorded as owed in TODO.md.
- Ungating `ProjectAdmissionPresence`.
- A new metric: no new operational state.
- Any change to the hub cards or *Meine Turniere*, which read rosters, not the gate.
- The admin page-visibility hint in `resources/js/pages/admin/pages/Edit.vue`, which is a different module's audience rule.
