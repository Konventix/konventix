# ADR-0011: AGPL-3.0-or-later open core

**Status:** accepted

## Context

Konventix should be commercializable by a 2–3 person team. Support-only revenue
(Zabbix model) was evaluated and rejected as non-scalable at that size.
pretix's twelve-year owner-led trajectory is both proof of market viability and
a caution.

## Decision

- Core is licensed **AGPL-3.0-or-later**.
- Primary revenue: hosted SaaS. Secondary: proprietary plugins.
- Before accepting external contributions: a **CLA** (not DCO) is required, so
  dual licensing stays possible.
- The Verein receives a written agreement: broad perpetual license (including
  an AGPL exemption — no disclosure obligation for its modifications) in
  exchange for explicit acknowledgment of the developer's sole copyright.
  Sublicensing, distribution rights, and support obligations are explicitly
  excluded.

## Consequences

- Copyright holder is **Markus Kohn**, solely. The IP agreement anticipated
  above turned out to be unnecessary: the predecessor (Lan-Software / LanCore)
  already ran under his own name, so there was no prior claim to disentangle and
  nothing to acknowledge. Dual licensing therefore stays open without any
  precondition, and the former "hard blocker" is closed.
- The license grant *to* the Verein (broad perpetual use, AGPL exemption for its
  own modifications) is now a favour the copyright holder may grant on his own
  terms rather than a bargaining chip. It is not a precondition for anything and
  is not required before commercialisation.
- A CLA is still required before accepting external contributions, for the same
  reason as before: it keeps dual licensing possible.
- Proprietary plugins must communicate with the core only over the
  out-of-process Extensibility tier or clearly separated module boundaries to
  keep the licensing line defensible.
