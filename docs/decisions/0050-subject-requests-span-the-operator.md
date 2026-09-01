# ADR-0050: A subject request spans the operator, and a stranger may file one

**Status:** accepted

## Context

Three things that are each defensible on their own contradict each other
once a data-subject request page is public.

**ADR-0016 already settled the scope, and the code does not implement
it.** That ADR chose one `users` row per human across the operator, and
gave the legal reason: the operator is a single legal entity and
therefore a single data controller, so an Art. 17 erasure request is
owed against *all* of that operator's processing of the person. It says
so in as many words — "Compliance addresses one subject, with one
deletion clock and one export, and fans that out across every module."
What shipped is the opposite. `DataSubjectRequest` uses
`BelongsToBrand`, and the fan-out contract every module implements is
`exportFor(string $subjectId, string $brandId)`. A person registered
with two of an operator's brands gets two archives, two deadlines and
two audit trails. Compliance's `TODO.md` has carried this as an open
item since Round 6; the ROADMAP carries it under Known gaps.

**The request form is behind `auth`, and the obligation is not.**
`POST /data-requests` reads `$request->user()`, so the only people who
can exercise Art. 15 or Art. 17 are the ones with an account still open.
Someone who never had an account, or who deleted theirs and later
remembers we hold their order, has no way in that does not involve
finding a support address. The obligation does not depend on whether the
subject can log in.

**A public form that mails any address you type is a weapon.** Without
verification, `POST /data-requests {email: victim@example.com, type:
deletion}` is a way to have a stranger's data erased, and the same form
with any address is an unmetered mail relay pointed at whoever the
sender dislikes. This is why the public page cannot be the authenticated
form with the middleware removed.

A fourth item rides along because it is the same module, the same
console and the same week's work: `WithdrawConsent` is unconditionally
self-service. `DELETE /consents/{consent}` checks ownership and
withdraws. For a policy whose lawful basis really is consent that is not
just correct but required — Art. 7(3) makes withdrawal as easy as
giving. For a photo release already acted on, or a liability waiver for
an event that has happened, it is wrong, and the operator has no way to
say so.

## Decision

1. **A subject request spans the operator.** `DataSubjectRequest` loses
   `BelongsToBrand`. Its `brand_id` becomes `filed_on_brand_id`,
   nullable, and carries provenance only: which brand's host the request
   came in on, so the acknowledgement renders in that brand's layout and
   the console can say where it arrived. It is never a query scope.
   `audit_log_entries` is already deliberately not brand-scoped for the
   same reason — a trail that stops at a brand boundary is not a trail —
   and this makes the two tables consistent.

2. **The fan-out contract takes a subject, not a subject and a brand.**
   `GdprHandler` becomes `exportFor(SubjectRef $subject): array` and
   `delete(SubjectRef $subject, DeletionMode $mode): void`. Dropping
   `$brandId` is the change that makes one archive span the operator:
   a handler that was handed a brand had no way to answer for the person
   rather than for the person-at-a-brand. All nine implementing modules
   are updated in this slice — Admission, Commerce, Competition,
   Compliance, Content, Identity, Messaging, Registration, Staffing.
   Handlers that read brand-owned tables now query without the brand
   scope, which is the `withoutBrandScope()` escape ADR-0010 exists to
   provide and which the console controllers already use.

3. **`SubjectRef` carries a ULID or an email, exactly one.** A subject
   who never had an account is still a subject. The concrete case today
   is Messaging: `messaging_suppressions.email` is keyed on the address
   with no account behind it, `messaging_message_records.recipient_email`
   holds the address every send went to, and both outlive any account —
   suppressions deliberately so, because a suppression exists precisely
   because that address said no. `SendMessageRequest` already accepts
   `recipientId: null` with a bare `recipientEmail` for exhibitor
   billing contacts, so the address-only recipient is an existing
   shape, not a hypothetical one. A
   value object with a nullable `userId` and a nullable `email` — with
   an invariant that exactly one is set — lets every handler answer what
   it can for a bare address and no-op otherwise, which is what the
   existing idempotence requirement already asks of them. Answering "we
   hold nothing about you" to someone whose address is in a newsletter
   list would be a false statement made under Art. 15.

   On the table this means `subject_id` becomes nullable and gains
   `subject_email` beside it, with a check constraint that exactly one
   is set. A request filed by an address that *does* match an account
   resolves to the ULID at verification time and is indistinguishable
   from a self-service one from then on — the email path is for
   addresses with no account behind them, not a second way to file.

4. **The public page verifies before it opens anything.** Three routes
   on the brand host: the form, the submission, and the link. A
   submission writes a row to `data_subject_request_verifications`
   holding the address, the request type, the brand it was filed on, a
   SHA-256 hash of a high-entropy token, and an expiry — and creates no
   `DataSubjectRequest` at all. Following the link is what opens the
   request, starts the statutory 30-day clock and sends the
   acknowledgement naming the deadline. The token is hashed and looked
   up by hash, the same shape `EntranceDevice` uses for its bearer
   token, so a database read does not hand out working links.

5. **The submission response does not depend on what we hold.** The page
   answers identically whether the address matches an account, matches
   nothing, or already has an open request. Any other behaviour is an
   account-enumeration oracle sitting on a public URL. Rate limiting is
   per-IP *and* per-address: per-IP alone lets a distributed sender mail
   one victim repeatedly, and per-address alone lets one sender walk a
   list.

6. **An unverified address lives 24 hours.** The row exists only to
   carry an address across one mail round-trip, and until the link is
   followed it is a stranger's address held on a stranger's say-so. An
   hourly sweep deletes expired unverified rows. Twenty-four hours is
   long enough for a mail read the next morning and short enough that
   the table is not an accumulating list of addresses somebody typed.
   Verified rows are deleted as soon as the request they opened exists;
   the request is the record from then on.

7. **`PolicyVersion` declares its lawful basis, and withdrawal follows
   from it.** A `lawful_basis` column in `consent` | `contract` |
   `legitimate_interest`. Where the basis is consent, withdrawal is
   self-service and cannot be configured otherwise — Art. 7(3) leaves no
   room, and a setting that lets an operator switch it off is a setting
   that lets an operator break the law by filling in a form. Where the
   basis is contract or legitimate interest, withdrawing is a request
   the operator accepts or refuses with a reason. Modelling the basis
   rather than the mechanic also makes the interface honest: the privacy
   page can say "this policy's basis is contract, so leaving it is a
   request" instead of showing a switch that reads as optional.

8. **A withdrawal request is a `DataSubjectRequest`, not a second
   queue.** A new `DataSubjectRequestType::ConsentWithdrawal` and a new
   `ConsentWithdrawalRequested` event. The deadline watcher, the audit
   trail, the refusal notice and the operator console then apply
   unchanged. The names say "consent" explicitly because `PolicyWithdrawn`
   is already taken by SPEC for a *policy* being retired, and two
   meanings of "withdrawal" one enum apart is how the wrong one gets
   handled.

9. **An account-less subject collects the archive through a mailed,
   single-use link.** `DataRequestController::download` authorizes by
   comparing `subject_id` to the signed-in user, and its docblock gives
   the reason: an export is every module's answer about one person, and
   a link carrying its own authority outlives the mailbox it was sent
   to. That reasoning holds and the authenticated path keeps it
   unchanged. But a subject with no account cannot sign in, so for them
   the alternative is not a worse link — it is no delivery at all. Their
   archive is fetched with a second hashed, single-use token mailed to
   the address they already verified, expiring with the archive itself.
   The narrow exposure is deliberate and bounded: single use, so a
   leaked link is spent; short-lived, so a forwarded mail goes stale;
   and issued only to an address that completed the verification
   round-trip in decision 4.

10. **Export gets an acknowledgement.** Today only deletion is
   acknowledged; an export request answers with a toast and then nothing
   until the archive is ready. Art. 12(3) does not distinguish. The
   acknowledgement template is shared by both types and names the
   deadline.

## Alternatives rejected

- **One request per brand, opened from one form.** The other shape
  Compliance's `TODO.md` recorded. It keeps the per-brand contract and
  the brand scope, and produces N archives, N deadlines and N chances to
  miss one — for a single controller's single obligation. It also makes
  the console's queue length a function of how many brands the operator
  runs rather than how many people asked.
- **Resolve the address to an account, or refuse.** Simpler, and it
  keeps `subject_id` non-nullable. Rejected because the refusal would be
  untrue for exactly the data that outlives accounts: a live suppression
  row and a delivery log keyed on the address, both of which Messaging
  keeps on purpose after an account is anonymized. Telling that person
  we hold nothing is worse than not offering the page.
- **A signed URL instead of a token row.** Laravel's signed routes would
  carry the address in the query string, which puts it in access logs,
  `Referer` headers and browser history, and gives no place to record an
  expiry that a sweep can act on. The address has to be stored to be
  deleted on schedule.
- **Opening the request in an `unverified` state instead of a separate
  table.** Fewer tables, but it puts unverified addresses in the table
  the console reads, makes every query filter them out, and sets a
  statutory deadline field on a request that has no statutory standing
  yet. The clock must not start before the request exists.
- **A `consent_withdrawal` switch in `self_service` |
  `operator_approved`.** How the ROADMAP first phrased it, and rejected
  for what it permits: nothing stops `operator_approved` on a
  consent-based policy, which is precisely the configuration Art. 7(3)
  forbids. Deriving the mechanic from the basis makes the illegal state
  unrepresentable.
- **Keeping the brand scope and shipping only the public page.** The
  cheapest slice, and the one that makes the existing bug worse: a
  public page on one brand's host is where a person discovers they must
  file the same request again on a domain they have never visited.

## Consequences

- Nine modules' GDPR handlers change signature in one commit. This is
  mechanical but not small, and the compiler does not catch a handler
  that keeps reading a brand-scoped query — the tests that assert one
  archive for a person on two brands are what catch it.
- Compliance's Known gap closes, and with it the ROADMAP entry that has
  carried it since Round 6.
- An operator now holds addresses for people with no account, in a table
  with a 24-hour sweep. That is a new category of personal data in the
  system, and it is deliberately the shortest-lived one.
- Only Messaging and Compliance can answer for an email-only subject
  today. Commerce cannot: an order is keyed by `payer_user_id` and
  carries no billing address column, so a guest-checkout order is not
  reachable by email — if guest checkout later stores one, Commerce's
  handler owes an email branch. Newsletter has no `GdprHandler` at all;
  it is a Phase 3 module holding no data yet, and it owes one before it
  stores its first subscription. Every other handler no-ops for an
  email-only subject, which is honest rather than lossy — but an
  export that says little must not be mistaken for proof we hold
  little.
- `lawful_basis` has no sensible default for existing rows. Every
  `PolicyVersion` in flight is backfilled to `consent`, which preserves
  today's unconditional self-service behaviour exactly; an operator who
  means contract or legitimate interest says so per version.
- Migrations here are expand/contract from ADR-0049 §9 onward:
  `filed_on_brand_id` is added and populated before `brand_id` stops
  being read, and the old column is dropped a release later.
- The public page is a new unauthenticated, mail-sending surface. Its
  rate limits are load-bearing, not hygiene.
