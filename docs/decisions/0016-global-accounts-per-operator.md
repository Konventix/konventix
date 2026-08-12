# ADR-0016: Global accounts with per-brand membership

**Status:** accepted

## Context

LanCore had one instance, one `users` table and one email-unique constraint, so
the question could not arise. Konventix has one operator running N brands on N
domains (ADR-0001), with brand-scoped sessions either way. The answer decides
whether one human visiting two brand domains is one `users` row or two, what
`UserRegistered.brand_id` means, and whether Compliance's subject requests span
the operator or stop at a brand.

The decisive argument is legal, not convenience. The operator is a single legal
entity and therefore a single data controller, so an Art. 17 erasure request is
owed against *all* of that operator's processing of the person, regardless of
which brand it was filed on. Per-brand accounts make the correct behaviour
unimplementable: Compliance would have to join across `user_id` values it has no
supported way to relate, over data held for ten years under HGB §257 (orders,
audit rows — LanCore's own `RetentionDataClass` says so). Choosing per-brand
accounts means shipping a known-unfulfillable legal obligation.

## Decision

**One `users` row per human across the operator. Email is unique operator-wide.**

- A `brand_memberships` row records first-seen brand, per-brand roles and
  per-brand preferences. Per-brand roles are already how ADR-0013's `team_id`
  key works; membership is the row that key hangs on.
- Sessions stay brand-scoped as ADR-0001 requires. A shared account is not a
  shared session.
- Compliance addresses one subject, with one deletion clock and one export, and
  fans that out across every module.
- **Admin views are brand-filtered from day one.** A brand sees only users who
  have interacted with it: admin lists, search and exports scope to membership by
  default. Seeing across brands requires the operator-level capability ADR-0015
  already defines for cross-brand access.

### Alternatives considered

**Per-brand accounts, `(brand_id, email)` unique.** Maximum brand separation, no
cross-brand enumeration risk, and a brand could be sold or spun out cleanly.
Rejected for the reason above; the secondary costs confirm it. One human becomes
N data subjects with N deletion clocks over data held by one controller, handles
are either per-brand (so `@markus` is two people) or global (so the accounts are
not really separate), cross-brand community is impossible, and every module's
read model needs a composite key.

**Global account without the brand-filtered views.** Rejected as insufficient
rather than wrong: it is this decision minus a policy, and the policy can
technically be added later without a migration. Taken now because retrofitting a
visibility rule across modules written without it is how a privacy incident
happens.

## Consequences

- Operator-global email uniqueness means a signup on brand B can reveal that the
  address already exists on brand A. Every "email already taken" path must
  therefore be made indistinguishable from success: always "check your inbox",
  never a validation error, and the same response shape on both branches. The
  same applies to password reset and invitation flows. This is a standing
  constraint on every form that accepts an email address, not a one-time fix.
- Brands that want to look like independent businesses leak a shared identity
  anyway — one login, one password, one reset mail. Accepted as the price of a
  coherent controller.
- Migrating to per-brand accounts later is effectively impossible. This is a
  one-way door and is being walked through deliberately.
- The brand-filtered view rule is cross-cutting: every admin list, search and
  export must respect it, and getting it wrong is a privacy incident rather than
  a bug. It cannot be delivered by one global switch, so it needs coverage per
  surface.
- Cross-brand community and single sign-on across brand domains become possible
  at all — a stated product differentiator rather than an integration project.
- Three decisions cascade from this one and are still open: D-IDP-02 (is consent
  scoped to the brand it was given on or to the operator), D-IDP-07 (what
  identity an account-less subject uses), and D-IDP-12 (who owns the handle and
  how unique it is). Each was blocked on this ADR; none of them is answered by
  it.
