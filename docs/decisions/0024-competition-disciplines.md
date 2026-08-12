# ADR-0024: Competition contests disciplines, not games

**Status:** accepted

## Context

Konventix is event-agnostic, so a quiz, a pinball tournament and a judged
contest have to fit the same Competition module that runs a CS tournament. The
measured evidence cuts both ways, which is why this was left open.

In favour of generality: LanBrackets' format engines are fully
participant-agnostic. A grep for `cs2|counter-strike|valorant|\bmap\b` across its
`app/` returns only `Collection::map()` and one unused doc comment. Its rulesets
are `best_of`, `points_win/draw/loss`, `allow_draws`, `group_size` and
`tiebreaker` — nothing in the engines knows what is being played.

Against it: LanCore's `competitions` table carries `game_id` and `game_mode_id`
that are non-nullable in practice; the duration estimator reads
`game.avg_match_minutes`; the eligibility rules include
`has_steam_account_linked`; `CompetitionType::Race` exists and drives nothing;
and `race_heat` and `final_stage` are stubs on both sides of the boundary. The
engines are general and everything around them is not.

Two things make this the moment to decide. ADR-0019 has just absorbed
LanBrackets into Competition, so those engines are ours and the question of what
they contest is ours too. And the game catalogue turns out not to be display
data: `Game` carries `avg_match_minutes`, `avg_stage_minutes` and
`match_length_minutes`, read by Competition's `DurationEstimator` and its board
controller; `signup_rules`, which is Competition's default eligibility tree;
`logo_path` and `banner_path`, which Competition falls back to for its own
branding; and `GameMode.team_size`, which the competition form defaults from.
`effectiveMatchLengthMinutes()` resolves `Competition > GameMode > Game`.
ADR-0012's phrase "a small read model for display" is not what the data says.

## Decision

**Competition references a `Discipline` — what is being contested. A video game
is one kind of discipline; a pinball table, a quiz format and a judged contest
are others. GameOps handles only the disciplines that are video games.**

- Duration hints, default eligibility and scoring shape hang off `Discipline`
  uniformly. There is no null-game case to fall back from, and therefore no
  null-safe fallback chain to maintain in every consumer.
- GameOps stays plugin-shaped because it *contributes* disciplines rather than
  being required. A brand with GameOps switched off loses automation, not its
  scheduling and eligibility defaults.
- Where the game catalogue itself lives is a separate question and is not settled
  here. What is settled is that ADR-0012's display-read-model framing does not
  survive contact with the measured field list, and has to be revisited when
  `Discipline` is designed.

### Alternatives considered

**Competition is game-optional.** Keep the model, make `game_id` nullable, and
route every game-derived value through a null-safe fallback. The smallest
possible change, and the format engines already support it — a pinball
tournament is a competition with a null game and a round-robin stage. Rejected
because "competition with no game" is a shape nobody exercises until the first
non-LAN brand arrives, so it rots unnoticed, and because the estimator's
defaults are tuned for CS and would be the only thing a quiz ever gets. A
nullable column is a generality claim the test suite cannot check.

**Two competition kinds: paired and judged.** Honest about what the bracket
engines can and cannot do — no pairing algorithm produces a hackathon result,
and pretending a judged contest is a degenerate bracket is a lie the code would
have to keep telling. Rejected as the primary decision because the judged shape
has no code today, which makes it a placeholder rather than a design. It is
recorded here as a known gap: `Discipline` says what is contested, it does not
supply a scoring model for a jury, and a hackathon still does not fit.

## Consequences

- **This enlarges Phase 2 for Competition, which has also just absorbed
  LanBrackets under ADR-0019.** The two decisions compound: the same module in
  the same phase takes on roughly 4,000 LOC, permanent ownership of
  double-elimination and Swiss correctness, *and* a new abstraction it did not
  have before. That should be planned for rather than discovered in the second
  week. ADR-0019's pre-agreed fallback does not help — it keeps the modelling
  side and defers the engines, and `Discipline` is on the modelling side. If
  Phase 2 is at risk, the way to stage this one is to ship `Discipline` with
  exactly one kind and add the rest later; it is a table and a reference, not a
  rewrite.
- GameOps' relationship to `Discipline` has to be designed while the only current
  driver is esports. That is designing an abstraction against a single example,
  which is how speculative generality usually starts. The mitigation is to demand
  a second example before the seam is fixed: model a pinball table or a quiz
  format in the demo seed and see whether it fits without contortion. If it does
  not, the abstraction is wrong while it is still cheap to change.
- Every competition form gains a discipline lookup where it had a game picker.
  Not more work for the organizer, but one more thing to seed and one more empty
  state for a brand that has configured nothing.
- ADR-0012 needs amending a second time. ADR-0019 already superseded its
  LanBrackets clause; its catalogue-ownership clause is now also in question. Two
  amendments to one ADR is a signal that ADR-0012 was written before the domain
  was measured, and whoever reads it next needs telling which parts still stand.
- The judged-contest gap stays open and explicit rather than being papered over.
  Recording "not yet" at release is better than shipping a bracket that claims to
  run a hackathon.
