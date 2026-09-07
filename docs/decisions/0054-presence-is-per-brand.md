# ADR-0054: Presence is per brand, and the shared account says so

**Status:** accepted

## Context

ADR-0016 made the account operator-wide and put "first seen, per-brand roles
and preferences" on a `brand_memberships` row. It left three questions
cascading and unanswered — D-IDP-02 (is consent scoped to the brand or the
operator), and, in GitLab #66, whether a person who only ever used brand A
sees brand B's events in their account, whether the third profile visibility
level applies brand-wide or per brand, whether notification consents are per
brand or shared, and whether it is recognisable that one operator stands
behind the brands. #66 asked for these to be settled before go-live because
they touch the data model and consent at once.

Reading the code against the questions shows that most of them were answered
in passing by slices that needed an answer to ship, each in the same
direction, none of them written down as the rule:

- `MyRegistrationsController` narrows to the current brand through the
  global scope and says in its docblock that a registration made with one
  brand is invisible on another's host "even to the account that made it".
- `ResolvesProfiles` requires a `CommunityMember` row on *this* brand under
  every visibility, and `event` visibility checks shared confirmed
  participations on *this* brand (ADR-0052 §3). The setting sits on `User`
  and is chosen once; the gate is evaluated per brand host.
- Policies, policy versions and consents all carry `BelongsToBrand`;
  `OutstandingConsents` only ever sees the current brand's required
  versions. A person who never visits brand B never sees, and never accepts,
  brand B's policies.
- Messaging's suppression list is per brand (ADR-0028), its topic/channel
  preferences are per recipient and operator-wide (`messaging_preferences`
  is keyed by recipient only), and its announcement fan-out is neither: it
  iterates every recipient the operator knows (#25).
- The operator is already named on every brand: the footer imprint is
  operator-wide by design, mail carries the operator's seller line, and the
  imprint obligation names the legal entity on every domain regardless.

So the architecture has a consistent answer — the **membership**, not the
account, is the unit of everything a brand shows or sends — and one place
where it is contradicted (the announcement fan-out), one place where it is
silent (the auth pages), and one place where a person is told the truth in
the wrong tone (a duplicate registration answers "email already taken",
which ADR-0016 forbids and Identity's `TODO.md` carries as #18).

The silence is the part that reads as a dark pattern. A person who knows
two brands but not that one operator runs both is told nowhere that the
account they create on A is the account they will have on B; the first
login on B writes the membership without a word. Four remedies were weighed
(alternatives below); the cheapest is also the one that satisfies an
obligation that already exists, since Art. 13 GDPR requires the controller
to be named at the moment of collection.

## Decision

1. **Presence is the membership.** Registrations, admissions, profile pages,
   consents and any future brand-facing list resolve against the current
   brand's membership and brand-scoped rows. An account with no membership
   on a brand has no presence there: nothing to show, nothing to send. This
   is what the global scope (ADR-0010) already does and what every new
   surface must keep doing; a cross-brand view is an operator capability
   (ADR-0015), never a default.

2. **Policy consent is per brand, captured on first use of that brand.**
   D-IDP-02 closes as its Option A. The inventory's objection — three
   near-identical privacy policies accepted three times — does not arise,
   because a person only meets brand B's policy set when they use brand B,
   and a withdrawal on A cannot silently change B's gate. Option C (a scope
   column on `Policy`) stays reopenable the day a policy genuinely has to be
   accepted once for the operator; nothing shipped forecloses it.

3. **Channel preferences are operator-wide; audiences follow the
   membership.** Which channels a person wants for a *kind* of notification
   is a fact about the person and stays one row per recipient. Who a brand
   may address at all is the set of its members: the announcement fan-out
   (#25) narrows to the brand's memberships from an Identity signal, and
   marketing opt-ins (Newsletter) are per brand, as that module's SPEC
   already assumes. Suppressions stay per brand (ADR-0028).

4. **The operator is named, not hidden.** Brands share one account openly.
   The footer imprint, the mail seller line and the policies already do
   this; the auth surfaces join them (5).

5. **The shared account is named and the sibling brands are listed on every
   auth surface.** Sign-up says whose account is being created, which sites
   it is valid on, and who the controller is; login says the same in the
   past tense; password reset and social-signup completion inherit it from
   the auth layouts. The first login on a brand the account has not touched
   before is announced once, at the moment `RecordBrandMembership` writes
   the row, naming the brand the account was first used on. An operator
   with a single brand shows the operator's name and no list. (#184)

6. **A duplicate registration is answered as ADR-0016 requires, and the
   mail carries the explanation.** "Check your inbox" on both branches, and
   the mail to the existing account says which operator holds it and on
   which brand it was first used, with login and reset links for the brand
   the attempt was made on. Password reset and Steam signup behave the same.
   This turns #18 from an enumeration fix into the moment the shared account
   is explained to the one person who most needs to hear it. (#18, pulled
   into Phase 2)

## Alternatives rejected

- **Accounts per brand, same details, brand-scoped.** ADR-0016 rejected
  this and called it a one-way door; the reason — one controller, one
  erasure obligation across every brand, no supported way to join per-brand
  subjects — has not changed. The transparency problem is real but is a
  presentation problem, and this buys it with a legal one.
- **One email, a different password per brand.** The email stays unique
  operator-wide, so the enumeration oracle stays; two-factor and the Steam
  identity are per account and cannot be split; sessions are already per
  brand, so nothing is gained there; and the reset mail cannot know which
  brand's password it resets without a brand-keyed credential table. N
  passwords for what the privacy policy still has to call one account.
- **An unbranded operator login page.** Single sign-on in disguise. Sessions
  are per brand domain (ADR-0001), so a login on the operator host has to
  hand a session across domains — a signed one-time redirect at minimum,
  OIDC once it has to be robust — with two-factor, verification mails and
  reset links following it to the operator host. It does make the shared
  identity obvious, the way a central ID does for large brand families, and
  it remains the shape to build if cross-brand SSO ever becomes a product
  goal. It is not the fix for a message a paragraph can carry.
- **Consent operator-wide (D-IDP-02 Option B).** Brand-specific policies
  (house rules, photo consent at one brand's events) would have nowhere to
  live, and a withdrawal on one brand would silently change another's gate.
- **Per-brand notification preferences.** `brand_memberships.preferences`
  exists for per-brand Identity preferences, but Messaging owns notification
  preferences (ADR-0041) and a channel choice is about the person, not the
  brand. The case "announcements from A but not B" is met by membership: a
  person who has not touched B is not in B's audience at all.

## Consequences

- No migration. Every decision above is either already the shipped
  behaviour or a rule for surfaces that do not exist yet.
- Two Phase 2 issues carry the code: #184 (auth surfaces name the operator
  and list the brands, first-contact notice) and #18 (enumeration-safe
  registration, reset and Steam signup, with the explanatory mail). #25
  (announcement audience by membership) keeps its Phase 5 slot; until it
  lands, decision 3 is the rule and the fan-out is the known exception, and
  a deployment with more than one brand must not publish announcements to
  `everyone` or `logged-in` before it is closed.
- Every future brand-facing list or send owes a test that a person with a
  membership on A only is invisible and unreachable on B. That is the test
  that catches the next fan-out written without the membership in mind.
- The brand list on the auth pages is public information (the imprint says
  the same), so a brand that must stay unannounced before its launch would
  need a flag on `brands`. None exists and none is added until such a brand
  exists.
- "Leave brand B but keep the account" has no path: deletion spans the
  operator (ADR-0050). It is not a go-live concern; it is recorded in
  Identity's `TODO.md` as a backlog item.
- D-IDP-02 is closed in the decision log; Identity's SPEC records the
  presence rule beside the account-scope answer it extends.
