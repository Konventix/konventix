# ADR-0023: One content entry table with typed side tables

**Status:** accepted

## Context

The premise needs a correction before the decision. Konventix's docs describe
Content as collapsing *three* publishing mechanisms into one. LanCore does not
have three. It has **two editorial mechanisms** — News and Announcement — and
one **syndication feed of Event-module data**, the LPPS document, which merely
shares the word "publishing". `BuildLanPartyDocument` reads `OrganizationSetting`,
`Event`, `Venue`, `Address` and `TicketType`; it never touches an article or an
announcement. Folding it into Content would import Event's data model into the
editorial module for no gain. It belongs to Event as a syndication feed, or
nowhere. The merge under discussion is two into one.

What the two carry differs substantially. News has a slug, a teaser, a hero
image, a tag array and five flat SEO/OpenGraph columns. Announcements have an
urgency enum (`silent` / `normal` / `emergency`), an audience enum, a
**non-nullable** `event_id`, and per-user dismissal rows. A brand-defined third
type will carry something else again. The obvious escape — a JSON attributes
blob per type — is forbidden by ADR-0009 and lesson #11, which is what makes
this a real decision rather than a default.

## Decision

**A core `content_entries` table holding the shared lifecycle, plus a 1:1 side
table per content type.**

- `content_entries` holds identity, the publication state machine and publish
  window, authorship, a nullable event binding, taxonomy, and the type
  discriminator. One publish path, one audit stream through Compliance, one
  cross-type listing query — which is what a brand front page actually asks for.
- `content_news` and `content_announcements` hold only what their type needs,
  in properly typed columns that are `NOT NULL` and constrained where the domain
  says they should be.
- Announcement's better habit generalises: an explicit publish action, separate
  from update, applies to every type.

### Alternatives considered

**One wide table with nullable type-specific columns.** The simplest queries,
one model, one policy, one audit stream, and adding a type is genuinely just a
migration that widens what is already there. Rejected because every new type
widens the table for all types, and because nullable columns carry no validity
guarantee — an announcement with `og_image` set is representable, and nothing in
the schema objects. The model then grows a `match($type)` in every accessor,
which is exactly the shape LanCore's `User` took (lesson #2).

**Separate tables per type sharing a `Publishable` concern.** Strongest typing,
no discriminator at all. Rejected because it reintroduces precisely what the
merge exists to remove: a cross-type listing becomes a union query, and every new
type is a full model, controller and policy again. Three controller sets, three
policies, three publish paths and three audit wirings are the thing being
deleted.

## Consequences

- Adding a content type is a **migration, not a data row**, and every read of a
  typed entry costs a **join**. Both are accepted deliberately. The alternative
  is type columns that are nullable and unconstrained, which is the
  column-spelled version of the JSON blob ADR-0009 forbids. The "adding a type is
  data" goal was overstated in the first place: a new type needs a form, a
  template and a policy regardless, so it was never purely data.
- Polymorphic eager loading over a mixed listing is fiddly and has to be handled
  deliberately, or the front page is an N+1 that strict models (ADR-0010) will
  only surface outside production.
- The merge loses the **schema-enforced guarantee that an announcement belongs to
  an event**. LanCore made `event_id` non-nullable on `announcements`; the merged
  entity cannot express "not null for this type" on a shared nullable column, so
  it degrades to a per-type validation rule. A data fix or a future write path
  can produce an eventless announcement and nothing at the storage layer will
  object.
- Comment up/down votes are dropped. `news_comment_votes` exists, but no ranking,
  no abuse handling and no display was ever built on it.
- Read and view analytics are dropped: `NewsArticleRead` and `AnnouncementsViewed`
  were emitted on view, and their only consumer was the achievement engine.
  Nothing else asked what had been read, so nothing else notices — but "how many
  people read this" becomes unanswerable, and if it is ever wanted it is new work
  rather than a reconnection.
- The satellite distribution tier in Announcement's audience enum
  (`lancore_only` / `satellites`) has no consumer until Extensibility ships, so
  audience narrows to the human-facing values at release.
- Urgency splits across two modules: the styling meaning stays in Content, the
  delivery meaning moves to Messaging. `silent` currently means "do not notify",
  which is a Messaging decision stored as a content attribute. Unpicking it is
  part of this merge, not a follow-up someone gets to defer.
