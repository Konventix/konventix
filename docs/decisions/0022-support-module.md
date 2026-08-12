# ADR-0022: A Support module, post-release

**Status:** accepted

## Context

LanHelp is a helpdesk with a knowledge base attached. Four models: `Ticket`
(requester, assignee, subject, description, status, priority, category, a
`context_snapshot` JSON column, resolved and closed timestamps), `TicketReply`
with an `is_internal` staff-note flag, `KnowledgeBaseArticle`, and a local
`User`. Three roles — user, staff, admin. A user files a ticket and may only
move it between open and closed; staff work a board at `/staff/tickets` and post
internal notes; admins get a stats dashboard and knowledge-base CRUD. The KB is
public and unauthenticated at `/kb`. Notifications are e-mail only, and staff
alerting is a single address in `LANHELP_STAFF_EMAIL`. About 3,000 LOC of PHP
across 62 files in `app/`, of which roughly 1.5 kLOC is the helpdesk logic
itself.

One piece is genuinely valuable. `TicketController::sanitizeContextSnapshot()`
whitelists `source_product`, `source_domain`, `event_reference`,
`seat_reference`, `tournament_reference`, `order_reference`, `team_reference`
and up to ten links, and LanCore's `AskForHelpButton.vue` builds exactly that
URL. "Help me with *this* order" already works — across an application boundary,
by URL-encoding what would otherwise be a foreign key.

**Nothing forced LanHelp's separate deployment.** Same Laravel 13, same PHP,
same Inertia v3, same Vue 3.5, same Tailwind v4. `DB_DATABASE=lanhelp` is a
logical database on the shared PostgreSQL cluster, on the same Redis and the
same Mailpit. No websockets (`BROADCAST_CONNECTION=log`), no scheduler, no
separate uptime tier, no offline mode. Both the landing page and the login page
hard-redirect to LanCore when `LANCORE_ENABLED` is true; local login survives
only as `/login?local`. It has the machinery of an independent application and
none of the reasons for one.

The integration tax is measurable rather than theoretical. LanCore is configured
with `announcement_path => /api/webhooks/announcements` for LanHelp, and LanHelp
implements no such route — it polls the feed instead. Either that push has been
dead-lettering, or the flag is false in every real environment and nobody
noticed. Two systems, one config file, no way to tell.

## Decision

**Konventix gains a nineteenth module, `Support`, Pennant-gated and delivered
post-release in Phase 3.** LanHelp is retired, not maintained.

- Support owns `SupportRequest`, replies with internal/external visibility, and
  assignment. `Ticket` does not survive as a name: it already means an admission
  credential in this domain, and ADR-0005 split Registration from Admission
  precisely to clean that word up.
- The context snapshot becomes a real reference — a ULID plus the owning module,
  not a URL string — and the "ask for help about this" affordance becomes a
  Platform UI slot that any module renders into.
- LanHelp's knowledge base becomes a Content type under ADR-0023's model. It is
  editorial content and Content is where editorial content lives.

### Alternatives considered

**Drop the helpdesk and route support to an off-the-shelf tool**, keeping the KB
in Content and pointing the affordance at a configurable URL. This deletes the
most code, support tooling is a commodity with good free tiers, and it is the
honest answer to "is a ticket system part of an event platform". Rejected as the
end state for two reasons. Support history stops being GDPR-exportable through
Compliance, which is an obligation rather than a nicety. And most external tools
accept a URL and nothing more, which is exactly what LanCore already does — so
the saving is the status quo minus a capability. It remains the correct interim:
nothing about a Phase 3 module stops an operator deep-linking a commodity tool
until Support ships, and nothing in that arrangement blocks Support later.

**`SupportRequest` as a Content type with a workflow.** No nineteenth module;
Content already owns entries, comments, moderation and publication state, and a
support request is arguably an unpublished entry with an assignee and a thread.
It would also put tickets next to the KB, which is where "this answer should
become an article" naturally happens. Rejected as a category error: Content's
SPEC says it owns *editorial* content and a private support conversation is not
editorial. The comment model would need per-comment internal/external visibility
that editorial comments have no use for, bending a clean module out of shape for
storage convenience.

## Consequences

- The module count goes from 18 to 19. This is already reflected in code: the
  architecture test now asserts 19 registered providers and the module-activation
  test moved with it. Every other count in the docs set has to stay in step.
- Support is Pennant-gated and lands in Phase 3, where it competes with Community
  and Agenda for the capacity of a 1–3 person team. Three post-release modules
  and one team means at least one slips, and Support is the one with a working
  commodity substitute — which makes it the honest candidate.
- **Support tooling is not a differentiator, so the real risk is building a
  mediocre helpdesk instead of integrating a good one.** Helpdesks grow: SLAs,
  macros, escalation rules, canned replies, satisfaction surveys. Each is a
  reasonable request that will be awkward to refuse once the module exists. The
  scope ceiling has to be written into Support's SPEC before the first line of
  code, or the module quietly consumes capacity that belongs to modules nobody
  else sells.
- Support overlaps conceptually with Messaging (inbound conversation versus
  outbound delivery) and with Community (threads and moderation), and will
  attract "why isn't this just X" for as long as it exists. The answer belongs in
  the SPEC rather than in whoever happens to remember this ADR.
- Turning the context snapshot into a typed reference is a real gain over an
  untrusted whitelist of URL parameters, but it means Support depends on every
  module it can reference being able to resolve a ULID Support does not own —
  through events or a resolver contract, per ADR-0004. That contract is
  cross-cutting and has to exist before the first reference type ships.
- Retiring LanHelp deletes an SSO client, a role-sync webhook receiver, a shadow
  user table and a polled announcement feed. It also deletes the public
  unauthenticated `/kb`, which returns as Content on the brand domain — a URL
  change for anyone who bookmarked it.
