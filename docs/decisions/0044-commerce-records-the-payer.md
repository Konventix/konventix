# ADR-0044: Commerce records the payer as a user ULID on every commercial root

**Status:** accepted

## Context

An order is keyed by `settles_type`/`settles_id` and nothing else. That was
right for ADR-0006 and ADR-0026 — Commerce never touches Registration's
models, and a free registration never touches Commerce — but it left one
question Commerce cannot answer: *what does this person owe?* The
`registration.mine.show` slot works because Registration proves ownership
first and then asks about one registration; a standalone "my orders" list,
the GDPR exporter Compliance's fan-out expects, and the cart of the coming
shop (a cart belongs to a person before it belongs to any registration) all
need the other direction. The two shapes on record were a subject read model
in Commerce projected from Identity's events, or Registration's export
carrying invoice references. The second closes only the GDPR item; the first
projects fields nothing displays, since the payer sees their own list signed
in.

## Decision

**Every Commerce root that a person owns carries `payer_user_id`, a plain
user ULID, named by whoever creates the root.** For an order settling a
registration that is Registration, through `registrantUserId` on
`RegistrationSettlementRequested`; for a cart it will be the signed-in
shopper. No Identity events are consumed and no profile is projected:
Commerce stores an identifier, never a name.

- `orders.payer_user_id` is nullable with no foreign key (ADR-0014), indexed
  with `brand_id`.
- `PlaceOrderFromSettlementRequest` stores it and, on its idempotent path,
  fills a null payer, so a replayed request converges.
- "My orders" (`/my/orders`, `AREA_ACCOUNT`) lists by that column under the
  ordinary brand scope.
- `CommerceGdprHandler` exports by it and **erases nothing**: issued
  invoices are retained under § 147 AO (Art. 17(3)(b) GDPR), and the payer
  link stays because Identity tombstones the account row — severing it would
  make a retained invoice unfindable in an audit.
- No backfill: Konventix has no order that predates this decision, and the
  seed re-runs from scratch. An order with a null payer is absent from "my
  orders" rather than guessed.
- Outbound Commerce events do not carry `payerUserId` until a consumer needs
  it; adding the scalar is the obvious widening then.

### Alternatives rejected

**A `commerce_payers` read model from `UserProfileUpdated`.** A table of
names and emails nothing displays is the GDPR liability Content's
`content_authors` migration already refused for the same reason.

**Registration's export carrying invoice references.** Leaves the cart with
no owner and "my orders" unbuildable.

## Consequences

- `RegistrationSettlementRequested` widens by one field; both sides are
  in-repo.
- A future Commerce root without a payer is an architecture bug, not a
  nullable column: the docblock on the column says so.
- The Compliance and ROADMAP Known-gap entries close.
