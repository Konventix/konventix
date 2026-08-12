# ADR-0030: German VAT scope at release

**Status:** accepted

## Context

Commerce's SPEC lists tax model scope as open: "Which German VAT cases must be
supported at release — standard and reduced rates, reverse charge for B2B
exhibitors, the small business exemption, and whether cross-border sales need
OSS handling." ADR-0017 already placed the two halves of this answer: the
price *declaration* (including a default tax rate) lives on `RegistrationType`,
and the *treatment* — which rate actually applies, whether reverse charge
overrides it — is computed in Commerce, because Commerce is where the billing
party's VAT ID is known. The Round 1 consequence recorded against D-COM-01
already commits to this split: "reverse charge needs the billing party's VAT
ID, which Registration does not have when a type is configured; the treatment
is applied in Commerce." This ADR fixes which German VAT cases that
Commerce-side treatment must actually cover at release.

## Decision

**Commerce supports the standard and reduced German VAT rates, applies reverse
charge for B2B exhibitors who supply a VAT ID, and exposes the §19 UStG
small-business exemption as an operator-level flag. OSS (cross-border
one-stop-shop reporting) is explicitly out of release scope.**

- Standard and reduced rates are the two rates `RegistrationType`'s price
  declaration (ADR-0017) may name; Commerce's calculation resolves the actual
  rate at settlement time, not a hand-typed percentage per type.
- Reverse charge applies when the billing party (not the individual attendee)
  supplies a VAT ID that passes validation and the sale qualifies as B2B
  intra-EU. It zeroes German VAT on the invoice and shifts the liability to
  the recipient, per the mechanism ADR-0017 already reserved for Commerce.
- §19 UStG exemption is a flag on the **operator**, not the brand. The
  exemption is an election a taxable legal person makes with the tax office;
  the operator is that legal person (ADR-0001 — brand is not the tenant, the
  operator is the legal entity), so a brand cannot independently be
  small-business-exempt while a sibling brand of the same operator is not.
  When the flag is set, invoices carry no VAT line at all — this is a
  distinct invoice code path, not a zero-rate line item.
- OSS reporting (aggregating and remitting VAT on B2C cross-border sales
  above the EU-wide threshold) is not built. Cross-border B2C sales are taxed
  under domestic German rules until this is revisited.

### Alternatives considered

**Include OSS at release.** Closes the compliance gap for cross-border B2C
sales from day one. Rejected: OSS requires per-buyer-country rate tables, a
quarterly reporting/remittance workflow, and threshold tracking across all EU
sales an operator makes — none of which exists yet and none of which is
needed for the domestic DACH events the release targets. Building it now
would be speculative generality against a case with no confirmed events
behind it.

**§19 UStG as a per-brand or per-registration-type flag.** Keeps the
exemption configurable at the level an organizer actually edits things.
Rejected: the exemption is not a business choice the platform can make
locally consistent per brand — a legal person is either small-business-exempt
or not, operator-wide, and modelling it below that level would let two
brands of one operator disagree about their own tax status, which the tax
office would not recognize either way.

## Consequences

- Reverse charge depends on VAT ID *validation*, not just presence. This ADR
  does not build VIES (EU VAT number validation) lookup; until it exists,
  reverse charge applies on an unvalidated VAT ID, which is an honest gap an
  organizer's bookkeeper must be told about, not a guarantee the invoice
  quietly makes.
- Cross-border B2C sales beyond the OSS threshold are technically
  non-compliant if an operator's brand ever sells enough outside Germany to
  cross it. This is accepted release risk, not a solved edge case — it needs
  a threshold-monitoring follow-up before any brand sells cross-border at
  volume.
- The §19 UStG flag interacts directly with invoice rendering (ADR-0031):
  an exempt operator's invoices need a legally required note
  ("Gemäß § 19 UStG wird keine Umsatzsteuer berechnet") instead of a VAT
  line, which is invoice-template scope, not a Commerce calculation change.
- "19%" or "7%" printed on a `RegistrationType` admin screen remains, as
  ADR-0017 already said, an intention rather than a guarantee — this ADR adds
  a third override on top of reverse charge: the operator's §19 flag can
  suppress VAT entirely regardless of what the type declares.
