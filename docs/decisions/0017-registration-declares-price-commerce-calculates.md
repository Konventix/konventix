# ADR-0017: Registration declares the price, Commerce calculates it

**Status:** accepted

## Context

LanCore puts an integer `price` in minor units directly on `TicketType` and
`Addon`, and `InteractsWithShop` reads it straight off the model. There is no tax
rate, no net/gross flag and no VAT ID anywhere in the schema. Every tax
capability in Konventix is therefore new construction, not a port: there is
nothing to copy and nothing to migrate from, only a decision to make before the
first paid flow ships.

The constraint that makes the placement hard is a three-way one. Registration
must declare what a registration costs, because it owns the offer. Commerce must
own pricing and tax calculation, because it owns money (ADR-0006). And ADR-0004
forbids either module from reading the other's models. ADR-0006 already says the
net-versus-gross *display* decision hangs on the RegistrationType rather than
being global, which points at an answer without giving one. The placement also
decides where German VAT lands: standard versus reduced rate, reverse charge for
B2B exhibitors, and the small-business exemption under §19 UStG.

## Decision

**`RegistrationType` carries a price declaration; Commerce computes the money.**

- The declaration is a value object from the first line of code — net amount, tax
  rate, net-versus-gross display flag — not three loose columns.
- It travels in the settlement event payload as plain scalars, per ADR-0004.
- Commerce computes line totals, tax and rounding, and snapshots the result onto
  the order. The order records what was charged; the type records only what was
  offered.
- A `free` settlement type needs no Commerce record at all.

### Alternatives considered

**Commerce owns a product catalogue; `RegistrationType` points at it by ULID.**
All pricing and tax knowledge in one module, including price history, tiered and
early-bird pricing, and per-audience tax treatment decided where the VAT ID is
known. Rejected for release: configuring a registration type becomes a two-object
job across two admin screens, which is a real cost for the common "one €30
visitor ticket" case, and Commerce stops being dormant for brands that only sell
simple tickets. Modelling the declaration as a value object keeps this path open
— a catalogue can later project into the value object rather than replacing it.

**Split by concern: Registration owns the amount, Commerce owns a tax-treatment
engine keyed by brand, billing party and tax class.** Attractive because it puts
the genuinely hard part where the data is. Rejected because "who owns pricing"
then has the answer "both", which is the kind of answer that erodes under
schedule pressure, and early-bird and tiered pricing still have no home either
way.

## Consequences

- Tax rules are split. The *rate* is chosen in Registration; the *treatment* —
  reverse charge, §19 UStG exemption — is applied in Commerce. Nobody can answer
  "where does VAT live" with a single module name, so both SPECs have to state
  the split or it will be re-argued on every tax ticket.
- A public page that must print an end price therefore needs Commerce in the
  request, which a landing page and a static-page block do not have. The
  answer (#234, D-COM-20, 2026-09-21) is a contract rather than an exception:
  Platform owns `Pricing\EndPriceCalculator`, Commerce binds the one
  implementation over its own `TaxCalculator`, and a declaring module passes
  net amounts and tax *classes* as scalars and gets amounts back. It fails
  soft — nothing bound, or a line Commerce cannot price, answers null and the
  surface prints the declared net amount with its label — because the
  declaration alone is still an honest thing to show, which is what this
  decision said in the first place.
- Reverse charge requires the billing party's VAT ID, which Registration does not
  have when a type is configured. The declared rate is therefore a default that
  the Commerce calculation may legitimately override on evidence Registration
  never sees. An organizer reading "19%" on the type screen is reading an
  intention, not a guarantee, and the UI must not pretend otherwise.
- Repricing history has no home and is deferred. Placed orders keep their
  snapshot, so nothing already sold is affected, but "what did this type cost in
  March" is not answerable. Accepted; the answer arrives with a catalogue if one
  ever does.
- Early-bird and tiered pricing have no home either. Since every tax capability
  is new construction anyway, the price declaration must be treated as a real
  domain type from the start; a bare integer column would foreclose the catalogue
  path that both of these eventually need.
- Phase 1, which offers free registrations only, ships without any Commerce
  entity at all.
