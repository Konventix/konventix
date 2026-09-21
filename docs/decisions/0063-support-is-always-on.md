# ADR-0063: Support is always on — the report channel is not a brand feature

**Status:** accepted. Amends ADR-0022.

## Context

ADR-0022 made Support the nineteenth module, "Pennant-gated and delivered
post-release". The first thing the module has to deliver is not the
helpdesk half but the report channel of issue #88: a floating button on
every page that files a `SupportRequest` with the correlation id and the
request context attached. #88 is labelled unverzichtbar and Go-Live; it is
the operator's early-warning system for a four-week-old platform on its
first sale.

Two facts collide with the gate. `ModuleGate::isActive()` reads "no brand in
context" as inactive, by design — refusing to act is the safe default when
the code cannot tell which brand it works for. The operator console runs
with no brand in context. A gateable Support could therefore never show its
launcher on the console, and the operator asked for exactly that (decision
2026-09-21: "yes for all brands and also admin"). Second, a brand that
switches the report channel off does not save anything and loses the one
path by which its visitors tell the operator that the purchase page is
broken; there is no product reason for that switch to exist.

## Decision

**Support is a kernel-style module: always active for every brand and on
the operator host, not gateable.** `SupportServiceProvider::$gateable =
false`; its routes carry no `ensure-module`; its launcher is rendered by
both shells by registered component name, the way the cookie-settings
dialog already is, and renders nothing when the module has not shipped it.

ADR-0022 stands in everything else: the entity is `SupportRequest`, never
`Ticket`; the typed reference replaces the URL snapshot; the knowledge base
is Content; the scope ceiling lives in `SPEC.md` (now written). Only the
sentence "Pennant-gated" is withdrawn.

Should a later, heavier half of Support (a staffed helpdesk with queues an
operator may not want) need a per-brand opt-in, it becomes a per-brand
feature beside the module, ADR-0047's shape (`ensure-feature:support:…`),
with the report channel staying on underneath it.

## Consequences

- Brand creation and `permissions:sync` materialize `support.desk` for every
  brand, the same as the other always-active modules' default roles.
- `filed_on_brand_id` on a request is provenance (ADR-0050), nullable for the
  console, never a scope; the console inbox lists across brands.
- The Compliance SPEC's argument for `$gateable = false` ("obligations are
  not a feature a brand opts into") now has a second instance; the module
  count and the activation test are unchanged, the gateable-module list in
  `ModuleGate::gateableModuleNames()` shrinks by one.
- `docs/architecture/module-map.md` row "Support" and ROADMAP's Support
  entries need their "post-release" wording updated to "report channel at
  go-live (#88), helpdesk half after the event (#48)".
