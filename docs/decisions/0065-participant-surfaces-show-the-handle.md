# ADR-0065: Participant surfaces show the handle

**Status:** accepted. Extends ADR-0051 and ADR-0052.

## Context

The 2026-09-21 audit found eight participant-facing surfaces printing
`users.name` — the field the account form labels "Full name" — and
twenty-odd places showing an event, an order, a registration or a person
without a link although a page for it exists. A news byline, a comment
author, the holder line of a ticket, the "reserved for you by" line of a
claim page: every one of them showed the real name to anyone the page was
open to, guests included on a public entry.

No ADR had settled which name a public or logged-in page shows. ADR-0051
gave every account a handle (`users.username`, unique operator-wide) and a
page (`/u/{username}`, Community's, on the brand host) and put the handle
into `UserRegistered` and `UserProfileUpdated`. ADR-0052 gave the page a
visibility gate. The read models that render people did not follow: five
of them (`content_authors`, `admission_people`,
`registration_registrants`, `messaging_recipients`, …) store
`display_name` alone; only Community's `community_profiles` stores
`username`, because its page is keyed by it.

The operator's rule, stated during the audit: **the chosen username on
public and logged-in pages, the real name only in the console and on
documents.** A person chose the handle to be seen by; the real name is what
the operator needs to run an event and what a legal document (an invoice,
a ticket that a door checks against an ID) carries.

## Decision

1. **On a brand host a person is rendered as `@username`.** Every
   participant-facing surface — public or behind a login — that names
   another person prints the handle. The display name appears on console
   pages (ADR-0029's host) and in documents (invoices, credit notes,
   tickets, badges) — nowhere else. A profile page is a person's own page
   and keeps ADR-0051 §7's shape (display name, handle, tagline); a mail
   may address its own recipient by name.

2. **The handle links to the profile when Community is active for the
   brand.** The link is `/u/{username}`, brand-relative, computed
   server-side by the module rendering the surface —
   `ModuleGate::isActive('community')` decides, the way Identity's welcome
   mail decides on `commerce:shop` — and shipped as a nullable URL beside
   the handle. Null means "print it plain": a brand without Community has
   no profile pages, and that is the gate working (ADR-0051 consequences).
   The profile keeps its own visibility rule (ADR-0052) and may answer 404
   to a viewer the link was shown to; a link that 404s is a rule of the
   page, not a bug of the byline.

3. **A person read model that feeds a participant surface stores
   `username` beside `display_name`**, projected from the same Identity
   events (`UserRegistered`, `UserProfileUpdated`) and backfilled by
   `identity:replay-profiles`. Nullable: a row that predates the column
   renders a neutral placeholder ("Participant", "Unassigned") until the
   replay runs, never the display name. The display name stays in the row
   for the console and the documents the same module renders.

4. **The real name never ships to a participant page's props.** The
   `Data` classes of these pages carry `…Username` and `…ProfileUrl`, not
   `…Name`: a name in the JSON of a public page is the name on the page,
   whether or not a template prints it.

5. **A tombstone clears the handle.** A module's GDPR handler that
   tombstones `display_name` sets `username` to null in the same write;
   Identity frees the handle on anonymization (`deleted-<ulid>`, ADR-0051
   §9), and a stale handle in a read model would otherwise print — or
   link to — a name that may be somebody else's tomorrow.

## Alternatives rejected

- **Read `users.username` live through a join.** Forbidden by ADR-0004:
  the modules rendering these surfaces may not read Identity's tables.
- **A shared "person" read model in Platform.** One table, one projector,
  every module reading it — a cross-module read of a table nobody owns,
  the god-object shape ADR-0004 was written against. Each module keeps its
  own row and its own projector, as it already does for the name.
- **Ship both name and handle and let the template choose.** The name is
  then in every page's props and one careless template away from the
  page. Point 4.
- **Compute the profile URL in the browser with a Wayfinder helper.** The
  helper exists whether or not Community is active for the brand; the
  gate is server-side knowledge, and a link to a route that the module
  gate 404s is the thing this ADR prevents.

## Consequences

- `content_authors` and `admission_people` gain a nullable `username`;
  `Registration`'s and `Messaging`'s person read models follow when their
  participant surfaces are touched (Task 17c and the ROADMAP's Messaging
  slices). `identity:replay-profiles` is re-run after deploy.
- Content's `PublicEntryData` and `PublicCommentData` lose `authorName`
  and gain `authorUsername` + `authorProfileUrl`; Admission's
  `MyTicketData`, `ManagedAdmissionData`, `TicketPageData` and
  `ClaimLandingData` replace their `…Name` with `…Username`. The ticket
  PDF keeps the display name (a document). The claim-link mail still
  names the inviter by display name to a third party — the one surface
  this ADR's first pass leaves, recorded in Admission's TODO.
- The console's author and holder columns are unchanged: they read
  `display_name` on the operator host and link to Identity's account page.
- The decision-log row D-IDP-14 points here.
