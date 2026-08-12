# Sponsoring — Specification

> Stable document. Describes what this module *is*. Task tracking lives in
> `TODO.md`.

> **Post-release module.** Sponsoring is Phase 3 in `docs/ROADMAP.md` —
> deliberately after release scope, and the module map explicitly says "port
> late". Do not start building it earlier. This document is provisional and
> intentionally thin.

## Purpose

Sponsoring records who sponsors a brand or one of its events, on what terms of
visibility, and where that visibility appears. The LanCore domain it succeeds
was thin, and this module starts equally small on purpose: a sponsor, a tier,
a period, and a placement. Everything commercial about a sponsorship deal, and
everything physical about an exhibitor's presence, belongs to other modules —
keeping those out is most of this module's design.

## Owns

- **Sponsor** — the sponsoring organization as this brand knows it: name, logo,
  website, public description, contact details.
- **Sponsorship** — the link between a sponsor and a brand or event, with its
  tier/package, active period, and display weight.
- **Placement** — where sponsors are shown. The rendering itself happens through
  block types that Sponsoring registers with Platform's block-based CMS; the
  blocks are Platform's machinery, the sponsor-specific block types are ours.

## Does not own

- **The money** — quotes, orders, invoices, payment terms, dunning for a
  sponsorship deal are Commerce's (ADR-0006). Sponsoring records that a
  sponsorship exists and how it is displayed; Commerce is the source of truth
  for what was billed and paid.
- **Booth and exhibition space** — Resources. A booth is a resource type with a
  time dimension, like any other bookable thing.
- **The exhibitor's registration, crew badges, and voucher admissions** —
  Registration and Admission (ADR-0005 uses exactly this example). A sponsorship
  may accompany an exhibitor registration; it is not the same object.
- **Brand static pages, the CMS, the block registry** — Platform. Sponsoring
  only contributes block types.
- **Articles and announcements about a sponsor** — Content.
- **Sponsor mentions in mailings** — Newsletter, and transactional mail is
  Messaging.
- **The organization record as a billing party** — Commerce owns the invoice
  recipient. A sponsor entry here is a public-facing profile, not a customer
  record; whether the two are ever linked is an open question.

## Public contract (events)

Events are the only way other modules may react to this one (ADR-0004).
Payloads carry ULIDs and scalars — never Eloquent models.

| Event | Emitted when | Payload |
|---|---|---|
| None yet; see Open questions | | |

No other module has a known reason to react to a sponsorship. Nothing is
invented here until one does.

## Consumes

Events from other modules that this module listens to, and the read models it
maintains from them.

| Event | Owning module | Why |
|---|---|---|
| None yet; see Open questions | | |

## Capabilities

Declared in `src/Enums/Permission.php`. Roles that grant them are per-brand
data, not code (ADR-0013).

Not yet defined. Expect a small administrative catalog around maintaining
sponsors and their placements; the public sponsor listing needs no capability.

## Activation

Pennant feature: **`sponsoring`**. Registration happens at boot regardless; the
feature gates routes, navigation, UI slots, and listeners (ADR-0003).

With the feature off, a brand has no sponsor administration and no sponsor
block types available to its pages. Nothing else is affected — exhibitor
registrations, booth bookings, and invoices all continue to work, because they
never belonged to this module in the first place.

## Open questions

- Where sponsor logos are stored: Platform media, Content, or module-local
  storage. Unsettled across the whole codebase, not just here.
- Whether a sponsor is ever linked to a Commerce customer/invoice recipient by
  ULID, or stays a purely public-facing record.
- Whether tiers/packages are free-form per brand or a fixed vocabulary.
- Whether sponsorship applies at brand level, event level, or both — and how
  inheritance would work if both.
- Whether sponsors ever need self-service access (uploading their own logo and
  copy), which would pull Identity and capabilities into scope.
