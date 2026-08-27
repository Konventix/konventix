# ADR-0047: The shop — a person-owned cart settles registrations and goods in one order, behind a brand feature, with a waiting room in front

**Status:** accepted

## Context

D-COM-10 split add-ons: an entitlement is a RegistrationType, a pure good is
a Commerce product, and deferred the product half. A cart makes the
deferred half unavoidable and adds the one thing neither half has: a person
who owns something before any registration exists (ADR-0044 gave orders a
payer; a cart is the first root that is a person's from the start). The
rush case adds a second requirement: the door must survive a thousand
arrivals without selling a seat twice.

## Decision

1. **Feature, not module.** Commerce is Core and cannot be switched off, so
   the shop is a per-brand Pennant feature `commerce:shop`, and the waiting
   room a second one, `commerce:waiting-room`. Platform gains
   `ensure-feature:{name}` beside `ensure-module` and a registry so the
   brand page can list a module's features.
2. **Products are Commerce's; stock is a quota.** `Product`/`ProductVariant`
   with the ADR-0030 tax class; stock consumed all-or-nothing under
   `lockForUpdate()`, the `Quota` contract copied.
3. **A registration joins the cart by submitting first.** With the feature
   on, an `instant` settlement request lands in the registrant's open cart
   as a line (keyed by its `request_key`) instead of placing an order; the
   Registration row exists and holds its quota from that moment. `invoice`
   settlements keep their direct order. A line that expires or is removed
   tells Registration (`CartLineExpired`/`CartLineRemoved`) to cancel the
   still-pending registration — the waitlist sweep's shape, reused.
4. **One order per checkout**, `settles_type = 'cart'`, each order line
   carrying `settles_kind`/`settles_ref`; `OrderPaid` keeps its shape and
   `OrderLineSettled` is emitted per line so Registration confirms exactly
   the registrations that were paid. Refunds are whole-order.
5. **The waiting room is Redis and a middleware alias.** A sorted set per
   brand, atomic `ZPOPMIN` admission in batches under a lock, a signed
   ticket cookie, a holding page that polls; Platform owns the alias and a
   gate contract, Commerce the implementation, Registration applies the
   alias to its forms. Not an anti-bot system: one ticket per account, a
   per-IP cap, Horizon-visible admission, nothing more.
6. **Done means load-tested**: a thousand arrivals against the seeded rush
   brand, no 5xx, no over-sold quota or stock.

### Alternatives rejected

**Intents in the cart, registrations created at checkout.** Nothing is held
while the ticket sits in the cart; a rush would fail at checkout instead of
at the door.

**Echo for the holding page.** A thousand WebSocket subscriptions is the
load Reverb must not take in a rush; polling a cheap endpoint is honest.

## Consequences

- `SESSION_DRIVER`/`CACHE_STORE` flip to Redis; a third Redis connection
  keeps queue traffic away from sessions.
- Registration imports `Konventix\Commerce\Events\*` — the sanctioned
  exception, now in both directions.
- `settles_type = 'cart'` is the second value the open domain string ever
  carried; consumers filter on it exactly as they filter `registration`.
