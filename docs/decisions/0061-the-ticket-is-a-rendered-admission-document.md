# ADR-0061: The ticket is a rendered Admission document, and its token is stable

**Status:** accepted (2026-09-11, design at
docs/superpowers/specs/2026-09-11-tickets-and-calendar-design.md; GitLab #72,
related #68, #98, #23)

## Context

GitLab #72 asks for a participant-facing ticket and a calendar file; #68 is
the environment ribbon whose watermark criterion named a "ticket PDF" before
one existed, and #98 is the entrance scan the ticket's code has to keep
working with. Designing #72 found, in the design's §2.1, that the KVX1 token
was never actually stable: `IssueAdmissionToken`, `AdmissionTokenPayload` and
`IssueBadge` all said in their docblocks that reissuing a token strands the
previously printed QR, but no nonce was ever stored on `admissions`, neither
`RecordScan` nor `VerifyAdmissionToken` compared one, and
`CheckInRejectionReason::NonceSuperseded` existed but was referenced nowhere.
Every token ever minted for an admission stayed valid for its full 60 days.
Only an operator printing a badge exercised this path, so it went unnoticed;
a participant downloading their own ticket makes it live, because every
download would otherwise mint another equally-live credential with no way to
retire the last one.

Three questions had to be settled together, because the answer to each
shapes the other two: what a "ticket" is relative to the credential that
already exists, whether promising token stability is safe now that a
participant — not just an operator — can trigger a render, and how a
half-kilobyte generated calendar file reaches a mail whose attachment
contract (`AttachmentData`) was deliberately built to keep a queued payload
small.

## Decision

1. **A ticket is not a new credential.** It is
   `Konventix\Admission\Documents\TicketDocumentRenderer::render(Collection
   $admissions): string`, rendering the admission's existing KVX1 token — the
   same one `RecordScan`/`VerifyAdmissionToken` already read, the same one a
   badge print already uses — onto an A4 document, one page per admission,
   watermarked by the new `Konventix\Platform\Documents\DocumentWatermark`
   service (`::html(...)`, `::testSystemBanner()`). No table gains a
   "ticket" row, no new token format exists, and the door's decision logic
   does not change: a ticket is a *rendering* of an access right already
   granted, not a second thing that could disagree with it. This is why the
   design's §8 keeps the ticket inside Admission rather than a new module —
   the credential and its printed form share one owner.

2. **The token is now stable, and a superseded one is refused — this changes
   a security property.** `admissions` gains `token_nonce_hash`
   (HMAC-SHA256, a dedicated pepper, the plaintext nonce never stored, per
   LanCore's TKT-F-018) and `token_issued_at`. `IssueAdmissionToken::
   current()` returns the admission's existing nonce when one is stored and
   mints one only the first time; `IssueAdmissionToken::rotate()` mints a
   fresh nonce and writes a `token_superseded` delta entry only when a
   previous hash was actually on file to supersede — an admission's first
   token, however it was minted, supersedes nothing. `handle()` is gone — a
   caller must now say which of the two it means, rather than reaching a
   single method that, until this ADR, could not have told the difference.
   `VerifyAdmissionToken` compares a presented token's nonce hash against
   the admission's stored one and refuses a mismatch with the existing
   `CheckInRejectionReason::NonceSuperseded`; an admission with no stored
   hash — everything minted before this deploy, and every admission an
   offline device has never seen — accepts any otherwise-valid token, so the
   rollout invalidates nothing already in circulation and a fresh scanner
   never closes a door on a whole queue. Before this ADR, "a lost ticket can
   be invalidated" was a docblock's claim with no code behind it; after it,
   the claim is true. `admission_tokens_superseded_total` (ADR-0059) is the
   number that says whether the newly-real enforcement is catching reissues
   rather than turning away genuine people. The docblocks in
   `IssueAdmissionToken`, `AdmissionTokenPayload` and `IssueBadge` that
   described the old, never-true behaviour are rewritten in the same change
   to describe the real one — `IssueAdmissionToken`'s explains why `handle()`
   is gone and why there is deliberately no backfill command for the hash,
   and `IssueBadge`'s says outright that it replaces the docblock's old,
   previously-false claim.

3. **A mail attachment may now be inline, which narrows a contract
   `AttachmentData` deliberately drew the other way.** Commerce's invoice
   mail is the reason `AttachmentData` only ever named a disk and a path: a
   queued job payload should not carry a PDF's bytes. A calendar file breaks
   that assumption in the opposite direction — it is derived, about half a
   kilobyte, immutable for a given event state, and writing it to a disk to
   satisfy the existing constructor would buy a cache-invalidation problem
   for a file that is cheaper to regenerate than to invalidate.
   `AttachmentData`'s constructor becomes `(string $as, ?string $disk =
   null, ?string $path = null, ?string $content = null, ?string $mime =
   null)`: exactly one of the disk form and the inline form must be set, the
   constructor refuses both and neither, and `TemplatedMail::attachments()`
   builds `Attachment::fromData(...)` for the inline form. Inline content
   over 64 KiB is refused — a ceiling, not a suggestion — so the disk form
   stays the only way to attach anything that could grow into a real payload
   problem, and nobody attaches a rendered PDF by widening the ceiling
   instead of using the door already built for it. This is also why the
   ticket itself is never mailed: it is a download, not an attachment, and
   this ADR does not reopen that.

## Consequences

- A device or a scan that has never seen an admission still accepts any
  token for it (no stored hash), the deliberate offline fail-open the
  design's §5 describes; only online verification is exact from the moment
  of this deploy.
- `IssueAdmissionToken::handle()` no longer exists. Every caller — the badge
  printer, the demo seeder, anything written against the old single method —
  has to be read again and moved to `current()` or `rotate()` explicitly; a
  caller that only ever wanted "don't strand the printed QR" was calling the
  method that, until now, could not have delivered that.
- `AttachmentData`'s two forms are mutually exclusive by construction, not
  by convention: a future caller cannot send both a path and inline bytes
  and have one silently win, and the refusal fires at construction, before
  any mailer is touched.
- `DocumentWatermark` and `Calendar\CalendarFile::forEvent(...)` both live
  in Platform, not in Admission or Event, specifically so the badge (#23)
  and the invoice can adopt the watermark, and any future consumer can call
  the calendar encoder, without importing Admission's or Event's models
  (ADR-0004). Neither module has adopted the watermark yet; the design's
  §12 leaves that as a deliberate follow-up, not an oversight.
- `entrance_delta_entries` gains a nullable `payload` JSON column so a
  `token_superseded` entry can carry the new hash to offline devices; every
  delta-entry kind that existed before this stays payload-less.
